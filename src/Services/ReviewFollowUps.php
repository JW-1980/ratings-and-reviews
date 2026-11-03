<?php

namespace RatingsAndReviews\Services;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RatingsAndReviews\Contracts\Reviewable;
use RatingsAndReviews\Contracts\RiskSignals;
use RatingsAndReviews\Contracts\VoterIdentity;
use RatingsAndReviews\Events\ReviewFollowUpDue;
use RatingsAndReviews\Events\ReviewFollowUpPosted;
use RatingsAndReviews\Exceptions\ToneNudgeRequired;
use RatingsAndReviews\Jobs\RefreshRatingAggregates;
use RatingsAndReviews\Models\Review;
use RatingsAndReviews\Models\ReviewFollowUp;
use RatingsAndReviews\Support\ContentStatus;
use RatingsAndReviews\Support\ContributionContext;
use RatingsAndReviews\Support\SectionSettings;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Follow-ups ("what do you think after a month?"): one per review, by its author, on an
 * approved review, from the section's `review_follow_up_after_days` after the review went
 * live (`approved_at`) until `review_follow_up_window_days` later. 0 days switches it off.
 *
 * The follow-up stores the new rating, the review's rating at that moment (so later edits of
 * the review do not move the "average change") and an optional plain-text note (tone check,
 * SpamGuard, RiskSignals as `kind` = `review_follow_up`). It belongs to the review: a held
 * note sends the review back to moderation, a report or erasure of the review covers it, and
 * resubmitting a deleted review removes it. The review's own rating never changes.
 *
 * rr:send-review-invitations also fires ReviewFollowUpDue once per review when its window
 * opens (claimed through `follow_up_invited_at`, so overlapping runs do not double-send).
 */
class ReviewFollowUps
{
    public const CONTRIBUTION_KIND = 'review_follow_up';

    public function __construct(
        private readonly SectionSettingsRepository $settings,
        private readonly SpamGuard $spam,
        private readonly RiskSignals $risk,
        private readonly VoterIdentity $identity,
        private readonly ToneNudge $tone,
        private readonly Participation $participation,
        private readonly Dispatcher $events,
    ) {}

    public function settingsFor(Review $review): SectionSettings
    {
        $subject = $review->reviewable;

        return $this->settings->for($subject instanceof Reviewable ? $subject->reviewSectionKey() : (string) $review->section);
    }

    /**
     * When the author may add a follow-up, or null when follow-ups are off for the review's
     * section or the review is not live.
     *
     * @return array{opens_at: Carbon, closes_at: Carbon}|null
     */
    public function window(Review $review): ?array
    {
        $settings = $this->settingsFor($review);
        $since = $review->approved_at;

        if ($settings->reviewFollowUpAfterDays <= 0 || $since === null || $review->status !== ContentStatus::Approved) {
            return null;
        }

        $opens = $since->copy()->addDays($settings->reviewFollowUpAfterDays);

        return ['opens_at' => $opens, 'closes_at' => $opens->copy()->addDays($settings->reviewFollowUpWindowDays)];
    }

    /**
     * Why this member may not add a follow-up now (a translated reason), or null when they may.
     *
     * @param  bool|null  $hasFollowUp  what the caller already knows, to save a query
     */
    public function reasonToRefuse(?Authenticatable $user, Review $review, ?bool $hasFollowUp = null): ?string
    {
        if ($user === null || (int) $user->getAuthIdentifier() !== $review->user_id) {
            return (string) __('ratings-and-reviews::messages.follow_up_not_author');
        }

        $window = $this->window($review);
        $subject = $review->reviewable;

        if ($window === null || ! $review->isPubliclyVisible() || ! $subject instanceof Reviewable || ! $subject->acceptsReviews()) {
            return (string) __('ratings-and-reviews::messages.follow_up_unavailable');
        }

        if ($hasFollowUp ?? ReviewFollowUp::query()->where('review_id', $review->id)->exists()) {
            return (string) __('ratings-and-reviews::messages.follow_up_exists');
        }

        if (now()->lt($window['opens_at'])) {
            return (string) __('ratings-and-reviews::messages.follow_up_too_early', ['date' => $window['opens_at']->toDateString()]);
        }

        if (now()->gt($window['closes_at'])) {
            return (string) __('ratings-and-reviews::messages.follow_up_too_late');
        }

        return null;
    }

    /**
     * @throws ValidationException
     * @throws ToneNudgeRequired
     * @throws AccessDeniedHttpException
     */
    public function add(Request $request, Authenticatable $author, Review $review, mixed $rating, ?string $body, bool $confirmTone = false): ReviewFollowUp
    {
        $subject = $review->reviewable;

        if ($subject instanceof Reviewable) {
            $this->participation->assertCanParticipate($author, $subject);
        }

        $reason = $this->reasonToRefuse($author, $review);

        if ($reason !== null) {
            throw ValidationException::withMessages(['follow_up' => $reason]);
        }

        $scale = $review->scale_max;
        $value = filter_var($rating, FILTER_VALIDATE_INT);

        if ($value === false || $value < 1 || $value > $scale) {
            throw ValidationException::withMessages(['rating' => __('ratings-and-reviews::messages.rating_range', ['max' => $scale])]);
        }

        $text = trim(strip_tags(str_replace(["\r\n", "\r"], "\n", (string) $body)));
        $max = max(1, (int) config('ratings-and-reviews.reviews.follow_ups.max_body_length', 2000));

        if (mb_strlen($text) > $max) {
            throw ValidationException::withMessages(['body' => __('ratings-and-reviews::messages.too_long', ['max' => $max])]);
        }

        $authorId = (int) $author->getAuthIdentifier();
        $override = $subject instanceof Reviewable ? $this->participation->holdOverride($subject, $author) : null;
        $held = $override === true;

        if ($text !== '') {
            $suggestion = $confirmTone ? null : $this->tone->check($text);

            if ($suggestion !== null) {
                throw new ToneNudgeRequired($suggestion);
            }

            $verdict = $this->spam->inspect($request, $text, $authorId);

            if ($verdict->refuse) {
                throw ValidationException::withMessages(['body' => __('ratings-and-reviews::messages.spam_refused')]);
            }

            $risk = $subject instanceof Reviewable ? $this->risk->forContribution(new ContributionContext(
                kind: self::CONTRIBUTION_KIND,
                userId: $authorId,
                subject: $subject,
                text: $text,
                request: $request,
                networkHash: $this->identity->networkHash($request),
                deviceHash: $this->identity->deviceHash($request),
                isEdit: true,
            )) : null;

            $held = $override ?? ($verdict->hold || ($risk?->hold ?? false));
        }

        try {
            $followUp = DB::transaction(function () use ($review, $authorId, $value, $text, $held): ReviewFollowUp {
                $locked = Review::query()->whereKey($review->id)->lockForUpdate()->firstOrFail();

                $followUp = ReviewFollowUp::query()->create([
                    'review_id' => $locked->id,
                    'user_id' => $authorId,
                    'rating' => $value,
                    'scale_max' => $locked->scale_max,
                    'initial_rating' => $locked->rating,
                    'initial_scale_max' => $locked->scale_max,
                    'body_text' => $text === '' ? null : $text,
                ]);

                if ($held) {
                    $review->forceFill(['status' => ContentStatus::Pending])->save();
                }

                return $followUp;
            });
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['follow_up' => __('ratings-and-reviews::messages.follow_up_exists')]);
        }

        $review->setRelation('followUp', $followUp);
        RefreshRatingAggregates::dispatch($review->reviewable_type, (string) $review->reviewable_id);
        $this->events->dispatch(new ReviewFollowUpPosted($followUp, $review, $held));

        return $followUp;
    }

    /**
     * Removes the follow-up (its author or a moderator of the review).
     *
     * @throws AccessDeniedHttpException
     * @throws ValidationException when there is none
     */
    public function delete(Review $review, Authenticatable $user): void
    {
        $own = (int) $user->getAuthIdentifier() === $review->user_id;

        if (! $own && ! $this->participation->mayModerate($user, $review)) {
            throw new AccessDeniedHttpException((string) __('ratings-and-reviews::messages.not_yours'));
        }

        if (ReviewFollowUp::query()->where('review_id', $review->id)->delete() === 0) {
            throw ValidationException::withMessages(['follow_up' => __('ratings-and-reviews::messages.follow_up_missing')]);
        }

        $review->setRelation('followUp', null);
        RefreshRatingAggregates::dispatch($review->reviewable_type, (string) $review->reviewable_id);
    }

    /**
     * Fires ReviewFollowUpDue for approved, public reviews whose follow-up window is open, that
     * have none yet and were not invited before. Per section with follow-ups switched on.
     *
     * @return int how many were invited
     */
    public function sendDue(?int $limit = null): int
    {
        $limit ??= max(1, (int) config('ratings-and-reviews.invitations.batch_size', 500));
        $sent = 0;

        $sections = Review::query()->where('status', ContentStatus::Approved->value)->whereNull('follow_up_invited_at')
            ->whereNotNull('section')->distinct()->pluck('section');

        foreach ($sections as $section) {
            $settings = $this->settings->for((string) $section);

            if ($settings->reviewFollowUpAfterDays <= 0 || $sent >= $limit) {
                continue;
            }

            $reviews = Review::query()
                ->where('section', (string) $section)
                ->where('status', ContentStatus::Approved->value)
                ->whereNull('follow_up_invited_at')
                ->where('approved_at', '<=', now()->subDays($settings->reviewFollowUpAfterDays))
                ->where('approved_at', '>=', now()->subDays($settings->reviewFollowUpAfterDays + $settings->reviewFollowUpWindowDays))
                ->whereDoesntHave('followUp')
                ->orderBy('approved_at')
                ->orderBy('id')
                ->limit($limit - $sent)
                ->get();

            foreach ($reviews as $review) {
                $claimed = Review::query()->whereKey($review->id)->whereNull('follow_up_invited_at')->update(['follow_up_invited_at' => now()]) === 1;

                if ($claimed) {
                    $this->events->dispatch(new ReviewFollowUpDue($review));
                    $sent++;
                }
            }
        }

        return $sent;
    }
}

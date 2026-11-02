<?php

namespace RatingsAndReviews\Services;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RatingsAndReviews\Contracts\PriceAtReviewResolver;
use RatingsAndReviews\Contracts\Reviewable;
use RatingsAndReviews\Contracts\RiskSignals;
use RatingsAndReviews\Contracts\VoterIdentity;
use RatingsAndReviews\Events\ReviewDeleted;
use RatingsAndReviews\Events\ReviewModerated;
use RatingsAndReviews\Events\ReviewPublished;
use RatingsAndReviews\Events\ReviewSubmitted;
use RatingsAndReviews\Exceptions\ToneNudgeRequired;
use RatingsAndReviews\Jobs\RefreshRatingAggregates;
use RatingsAndReviews\Models\Review;
use RatingsAndReviews\Models\ReviewTopicRating;
use RatingsAndReviews\Support\Comparison;
use RatingsAndReviews\Support\ContentStatus;
use RatingsAndReviews\Support\ContributionContext;
use RatingsAndReviews\Support\Money;
use RatingsAndReviews\Support\SectionSettings;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;

/**
 * Submitting, editing and deleting reviews, and moderating them.
 *
 * One review per user and subject. A review a moderator rejected or marked as spam never
 * returns to approved on its own: editing or resubmitting it (also after the author deleted
 * it) makes it pending again, and only a moderator can approve it. Every change that can
 * move the subject's numbers queues RefreshRatingAggregates after the transaction commits.
 *
 * With the review the service stores: the context facets, "compared with…" (another
 * Reviewable subject from the morph map the author can see, with better/worse/same), the
 * quality score of the ReviewQualityMeter and, on submission, the price at that moment from
 * the PriceAtReviewResolver (a failing resolver is reported and leaves the price empty).
 * The body is cleaned with the author's trust-level tools; a hostile tone answers 422
 * unless `confirm_tone` is set; submitting completes the author's review invitation.
 */
class ReviewService
{
    public function __construct(
        private readonly SectionSettingsRepository $settings,
        private readonly HtmlSanitizer $sanitizer,
        private readonly SpamGuard $spam,
        private readonly RiskSignals $risk,
        private readonly VoterIdentity $identity,
        private readonly RatingTopicCatalog $topics,
        private readonly PriceAtReviewResolver $prices,
        private readonly Dispatcher $events,
        private readonly TrustLevels $trust,
        private readonly ToneNudge $tone,
        private readonly ReviewQualityMeter $quality,
        private readonly ReviewFacets $facets,
        private readonly SubjectResolver $subjects,
        private readonly ReviewInvitations $invitations,
    ) {}

    /**
     * @param  array<string, mixed>  $data  rating, title, body, pros, cons, topic_ratings, facets, compared_type, compared_id, comparison, confirm_tone
     *
     * @throws ValidationException
     * @throws ToneNudgeRequired
     */
    public function submit(Request $request, Authenticatable $author, Model&Reviewable $subject, array $data): Review
    {
        $settings = $this->settings->for($subject->reviewSection());
        $this->assertOpen($subject, $settings);

        $authorId = (int) $author->getAuthIdentifier();
        $content = $this->content($settings, $subject, $data, $author);
        $this->checkTone($content['tone_text'], (bool) ($data['confirm_tone'] ?? false));
        $verdict = $this->spam->inspect($request, $content['spam_text'], $authorId);

        if ($verdict->refuse) {
            throw ValidationException::withMessages(['body' => __('ratings-and-reviews::messages.spam_refused')]);
        }

        $risk = $this->risk->forContribution(new ContributionContext(
            kind: 'review',
            userId: $authorId,
            subject: $subject,
            text: $content['spam_text'],
            request: $request,
            networkHash: $this->identity->networkHash($request),
            deviceHash: $this->identity->deviceHash($request),
        ));
        $price = $this->priceNow($subject);

        $hold = $verdict->hold || $risk->hold || $settings->reviewRequiresApproval;

        try {
            [$review, $resubmission, $published] = DB::transaction(function () use ($request, $subject, $authorId, $content, $hold, $risk, $price): array {
                $existing = Review::query()->withTrashed()
                    ->forSubject($subject)
                    ->where('user_id', $authorId)
                    ->lockForUpdate()
                    ->first();

                if ($existing !== null && ! $existing->trashed()) {
                    throw ValidationException::withMessages(['review' => __('ratings-and-reviews::messages.review_exists')]);
                }

                $status = $hold || ($existing !== null && $existing->status->isRefused())
                    ? ContentStatus::Pending
                    : ContentStatus::Approved;

                $review = $existing ?? new Review([
                    'reviewable_type' => $subject->getMorphClass(),
                    'reviewable_id' => $subject->getKey(),
                    'user_id' => $authorId,
                ]);

                $review->fill($content['attributes'] + [
                    'status' => $status,
                    'counted' => $risk->counted,
                    'ip_hash' => $this->identity->ipHash($request),
                    'network_hash' => $this->identity->networkHash($request),
                    'device_hash' => $this->identity->deviceHash($request),
                    'helpful_count' => $existing?->helpful_count ?? 0,
                ]);

                if ($existing !== null) {
                    $review->deleted_at = null;
                    $review->edited_at = now();
                }

                $review->price_cents = $price?->cents;
                $review->price_currency = $price?->currency;
                $published = $this->claimPublication($review);
                $review->save();
                $this->syncTopicRatings($review, $content['topic_ratings']);
                $this->invitations->complete($authorId, $review->reviewable_type, $review->reviewable_id);

                return [$review, $existing !== null, $published];
            }, 3);
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['review' => __('ratings-and-reviews::messages.review_exists')]);
        }

        $review->refresh();
        $this->refreshAggregates($review);
        $this->events->dispatch(new ReviewSubmitted($review, $resubmission));

        if ($published) {
            $this->events->dispatch(new ReviewPublished($review));
        }

        return $review;
    }

    /**
     * The author rewrites their review. A refused review becomes pending (a resubmission);
     * an approved one stays approved unless the new text is held.
     *
     * @param  array<string, mixed>  $data  as for submit()
     *
     * @throws ValidationException
     * @throws ToneNudgeRequired
     */
    public function update(Request $request, Authenticatable $author, Review $review, array $data): Review
    {
        $subject = $review->reviewable;

        if (! $subject instanceof Reviewable) {
            throw ValidationException::withMessages(['review' => __('ratings-and-reviews::messages.reviews_closed')]);
        }

        $settings = $this->settings->for($subject->reviewSection());
        $this->assertOpen($subject, $settings);

        $authorId = (int) $author->getAuthIdentifier();
        $content = $this->content($settings, $subject, $data, $author);
        $this->checkTone($content['tone_text'], (bool) ($data['confirm_tone'] ?? false));
        $verdict = $this->spam->inspect($request, $content['spam_text'], $authorId, $review);

        if ($verdict->refuse) {
            throw ValidationException::withMessages(['body' => __('ratings-and-reviews::messages.spam_refused')]);
        }

        $risk = $this->risk->forContribution(new ContributionContext(
            kind: 'review',
            userId: $authorId,
            subject: $subject,
            text: $content['spam_text'],
            request: $request,
            networkHash: $this->identity->networkHash($request),
            deviceHash: $this->identity->deviceHash($request),
            isEdit: true,
        ));

        $hold = $verdict->hold || $risk->hold || $settings->reviewRequiresApproval;

        $published = DB::transaction(function () use ($review, $content, $hold, $risk): bool {
            $locked = Review::query()->whereKey($review->id)->lockForUpdate()->firstOrFail();

            $status = $hold || $locked->status !== ContentStatus::Approved ? ContentStatus::Pending : ContentStatus::Approved;

            $review->fill($content['attributes'] + [
                'status' => $status,
                'counted' => $risk->counted,
                'edited_at' => now(),
            ]);

            $published = $this->claimPublication($review);
            $review->save();
            $this->syncTopicRatings($review, $content['topic_ratings']);

            return $published;
        }, 3);

        $this->refreshAggregates($review);
        $this->events->dispatch(new ReviewSubmitted($review, true));

        if ($published) {
            $this->events->dispatch(new ReviewPublished($review));
        }

        return $review;
    }

    /** Soft-deletes a review; null moderator: the author removed it. */
    public function delete(Review $review, int|string|null $moderatorId = null): void
    {
        $review->delete();
        $this->refreshAggregates($review);
        $this->events->dispatch(new ReviewDeleted($review, $moderatorId));
    }

    /**
     * A moderator's decision. Approving publishes the review (announced once); any change
     * refreshes the aggregates.
     */
    public function moderate(Review $review, ContentStatus $status, int|string $moderatorId, ?string $note = null): Review
    {
        [$previous, $published] = DB::transaction(function () use ($review, $status): array {
            $locked = Review::query()->withTrashed()->whereKey($review->id)->lockForUpdate()->firstOrFail();
            $previous = $locked->status;

            if ($previous === $status) {
                return [$previous, false];
            }

            $review->status = $status;
            $published = $this->claimPublication($review);
            $review->save();

            return [$previous, $published];
        });

        if ($previous === $status) {
            return $review;
        }

        $this->refreshAggregates($review);
        $this->events->dispatch(new ReviewModerated($review, $previous, $status, $moderatorId, $note));

        if ($published) {
            $this->events->dispatch(new ReviewPublished($review));
        }

        return $review;
    }

    /**
     * @throws ValidationException
     */
    private function assertOpen(Model&Reviewable $subject, SectionSettings $settings): void
    {
        if (! $settings->enabled || ! $subject->acceptsReviews()) {
            throw ValidationException::withMessages(['review' => __('ratings-and-reviews::messages.reviews_closed')]);
        }
    }

    /**
     * Validates and cleans the submitted content.
     *
     * @param  array<string, mixed>  $data
     * @return array{attributes: array<string, mixed>, topic_ratings: array<int, array{value: int, scale_max: int}>, spam_text: string, tone_text: string}
     *
     * @throws ValidationException
     */
    private function content(SectionSettings $settings, Model&Reviewable $subject, array $data, Authenticatable $author): array
    {
        $authorId = (int) $author->getAuthIdentifier();
        $rating = filter_var($data['rating'] ?? null, FILTER_VALIDATE_INT);

        if ($rating === false || $rating < 1 || $rating > $settings->reviewScale) {
            throw ValidationException::withMessages(['rating' => __('ratings-and-reviews::messages.rating_range', ['max' => $settings->reviewScale])]);
        }

        $body = (string) ($data['body'] ?? '');

        if ($this->sanitizer->exceedsInputLimit($body)) {
            throw ValidationException::withMessages(['body' => __('ratings-and-reviews::messages.too_large')]);
        }

        $clean = $this->sanitizer->clean($body, $this->trust->for($author, $settings)->settings, $authorId);
        $length = mb_strlen($clean->text);
        $maxLength = max(1, (int) config('ratings-and-reviews.reviews.max_body_length', 10000));

        if ($length < $settings->reviewMinLength) {
            throw ValidationException::withMessages(['body' => __('ratings-and-reviews::messages.too_short', ['min' => $settings->reviewMinLength])]);
        }

        if ($length > $maxLength) {
            throw ValidationException::withMessages(['body' => __('ratings-and-reviews::messages.too_long', ['max' => $maxLength])]);
        }

        $extended = $settings->reviewExtendedFields;
        $title = $extended ? trim(strip_tags((string) ($data['title'] ?? ''))) : '';
        $pros = $extended ? $this->points($data['pros'] ?? null) : null;
        $cons = $extended ? $this->points($data['cons'] ?? null) : null;
        $topicRatings = $this->topics->validatedRatings($subject, $data['topic_ratings'] ?? null);
        $comparison = $this->comparison($subject, $author, $data);

        $quality = $this->quality->assess([
            'text' => $clean->text,
            'pros' => count($pros ?? []),
            'cons' => count($cons ?? []),
            'topics_rated' => count($topicRatings),
            'topics_available' => $this->topics->effectiveFor($subject)->count(),
            'extended_fields' => $extended,
        ]);

        return [
            'attributes' => [
                'rating' => $rating,
                'scale_max' => $settings->reviewScale,
                'title' => $title === '' ? null : $title,
                'body_html' => $clean->html,
                'body_text' => $clean->text,
                'pros' => $pros,
                'cons' => $cons,
                'facets' => $this->facets->validate($data['facets'] ?? null),
                'compared_type' => $comparison['type'] ?? null,
                'compared_id' => $comparison['id'] ?? null,
                'comparison' => $comparison['comparison'] ?? null,
                'quality_score' => $quality->score,
            ],
            'topic_ratings' => $topicRatings,
            'spam_text' => trim($title.' '.$clean->text),
            'tone_text' => trim($title.' '.implode(' ', $pros ?? []).' '.implode(' ', $cons ?? []).' '.$clean->text),
        ];
    }

    /**
     * "Compared with…": all three fields or none. The alternative must be a Reviewable in
     * the morph map that the author can see, and not the subject itself.
     *
     * @param  array<string, mixed>  $data
     * @return array{type: string, id: int, comparison: Comparison}|null
     *
     * @throws ValidationException
     */
    private function comparison(Model&Reviewable $subject, Authenticatable $author, array $data): ?array
    {
        $type = $data['compared_type'] ?? null;
        $id = $data['compared_id'] ?? null;
        $comparison = $data['comparison'] ?? null;

        if (($type === null || $type === '') && ($id === null || $id === '') && ($comparison === null || $comparison === '')) {
            return null;
        }

        $value = is_string($comparison) ? Comparison::tryFrom($comparison) : null;

        if ($value === null) {
            throw ValidationException::withMessages(['comparison' => __('ratings-and-reviews::messages.comparison_invalid')]);
        }

        try {
            $compared = is_string($type) && (is_int($id) || (is_string($id) && ctype_digit($id)))
                ? $this->subjects->resolve($type, (string) $id, Reviewable::class)
                : null;
        } catch (NotFoundHttpException) {
            $compared = null;
        }

        if ($compared === null || ! $compared->reviewsVisibleTo($author)) {
            throw ValidationException::withMessages(['compared_id' => __('ratings-and-reviews::messages.compared_unavailable')]);
        }

        if ($compared->getMorphClass() === $subject->getMorphClass() && (string) $compared->getKey() === (string) $subject->getKey()) {
            throw ValidationException::withMessages(['compared_id' => __('ratings-and-reviews::messages.compared_self')]);
        }

        return ['type' => $compared->getMorphClass(), 'id' => (int) $compared->getKey(), 'comparison' => $value];
    }

    /**
     * @throws ToneNudgeRequired
     */
    private function checkTone(string $text, bool $confirmed): void
    {
        $suggestion = $confirmed ? null : $this->tone->check($text);

        if ($suggestion !== null) {
            throw new ToneNudgeRequired($suggestion);
        }
    }

    /**
     * @return list<string>|null
     */
    private function points(mixed $values): ?array
    {
        $points = array_values(array_filter(array_map(
            fn (mixed $value): string => is_string($value) ? trim(strip_tags($value)) : '',
            (array) $values,
        ), fn (string $value): bool => $value !== ''));

        return $points === [] ? null : $points;
    }

    /**
     * Marks the first publication. True only when this change makes it public for the first time.
     */
    private function claimPublication(Review $review): bool
    {
        if ($review->status === ContentStatus::Approved && $review->approved_at === null) {
            $review->approved_at = now();

            return true;
        }

        return false;
    }

    /**
     * The subject's price right now, for "price then". Stored on every (re)submission, not
     * on edits. A resolver error is reported and must not cost the reviewer their review;
     * an invalid amount or currency is ignored.
     */
    private function priceNow(Model $subject): ?Money
    {
        if (! $this->prices->isEnabled()) {
            return null;
        }

        try {
            $price = $this->prices->priceAt($subject, now());
        } catch (Throwable $exception) {
            report($exception);

            return null;
        }

        $currency = $price === null ? '' : strtoupper(trim($price->currency));

        if ($price === null || $price->cents < 0 || $price->cents > 4294967295 || preg_match('/^[A-Z]{3}$/', $currency) !== 1) {
            return null;
        }

        return new Money($price->cents, $currency);
    }

    /**
     * @param  array<int, array{value: int, scale_max: int}>  $ratings
     */
    private function syncTopicRatings(Review $review, array $ratings): void
    {
        ReviewTopicRating::query()->where('review_id', $review->id)->whereNotIn('topic_id', array_keys($ratings) ?: [0])->delete();

        foreach ($ratings as $topicId => $rating) {
            ReviewTopicRating::query()->updateOrCreate(
                ['review_id' => $review->id, 'topic_id' => $topicId],
                ['value' => $rating['value'], 'scale_max' => $rating['scale_max']],
            );
        }
    }

    private function refreshAggregates(Review $review): void
    {
        RefreshRatingAggregates::dispatch($review->reviewable_type, (string) $review->reviewable_id);
    }
}

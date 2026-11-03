<?php

namespace RatingsAndReviews\Services;

use DateTimeInterface;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RatingsAndReviews\Contracts\Reviewable;
use RatingsAndReviews\Contracts\RiskSignals;
use RatingsAndReviews\Contracts\VoterIdentity;
use RatingsAndReviews\Events\TopicRatingsSaved;
use RatingsAndReviews\Jobs\RefreshRatingAggregates;
use RatingsAndReviews\Models\RatingTopic;
use RatingsAndReviews\Models\TopicRating;
use RatingsAndReviews\Support\ContributionContext;

/**
 * Rating topics of a subject without writing a review (section setting
 * `standalone_topic_ratings`), and importing such scores.
 *
 * A member has at most one score per subject and topic. rate() takes the member's changes as
 * [topic id => value]; a null or empty value removes that score. Values are validated like
 * the topic ratings of a review (an active topic of the subject, 1 … its scale). RiskSignals
 * is asked as for a contribution (`kind` = `topic_rating`, empty text): `counted` false, or a
 * hold (there is no moderation queue for scores), keeps the member's scores out of the
 * averages, with the reason stored. Every change queues RefreshRatingAggregates.
 *
 * In the averages a standalone score counts unless the same member rated the same topic in a
 * counted review of the subject; then the review's score is the one counted.
 */
class TopicRatings
{
    public const CONTRIBUTION_KIND = 'topic_rating';

    public function __construct(
        private readonly SectionSettingsRepository $settings,
        private readonly RatingTopicCatalog $topics,
        private readonly RiskSignals $risk,
        private readonly VoterIdentity $identity,
        private readonly Dispatcher $events,
    ) {}

    public function isEnabledFor(Model&Reviewable $subject): bool
    {
        $settings = $this->settings->for($subject->reviewSectionKey());

        return $settings->enabled && $settings->standaloneTopicRatings;
    }

    /**
     * @param  array<array-key, mixed>  $ratings  topic id => value (null or '' removes the score)
     * @return array<int, int> the member's scores afterwards: [topic id => value]
     *
     * @throws ValidationException
     */
    public function rate(Request $request, Authenticatable $user, Model&Reviewable $subject, array $ratings): array
    {
        if (! $this->isEnabledFor($subject) || ! $subject->acceptsReviews()) {
            throw ValidationException::withMessages(['ratings' => __('ratings-and-reviews::messages.topic_ratings_closed')]);
        }

        $userId = (int) $user->getAuthIdentifier();
        $removed = [];

        foreach ($ratings as $key => $value) {
            if (($value === null || $value === '') && (is_int($key) || (is_string($key) && ctype_digit($key)))) {
                $removed[] = (int) $key;
            }
        }

        $valid = $this->topics->validatedRatings($subject, $ratings, 'ratings');

        if ($valid === [] && $removed === []) {
            throw ValidationException::withMessages(['ratings' => __('ratings-and-reviews::messages.topic_ratings_empty')]);
        }

        $verdict = $this->risk->forContribution(new ContributionContext(
            kind: self::CONTRIBUTION_KIND,
            userId: $userId,
            subject: $subject,
            text: '',
            request: $request,
            networkHash: $this->identity->networkHash($request),
            deviceHash: $this->identity->deviceHash($request),
        ));
        $counted = $verdict->counted && ! $verdict->hold;
        $reason = $counted ? null : ($verdict->hold ? $verdict->holdReason : $verdict->uncountedReason);
        $hashes = [
            'ip_hash' => $this->identity->ipHash($request),
            'network_hash' => $this->identity->networkHash($request),
            'device_hash' => $this->identity->deviceHash($request),
        ];

        $save = function () use ($subject, $userId, $valid, $removed, $counted, $reason, $hashes): void {
            DB::transaction(function () use ($subject, $userId, $valid, $removed, $counted, $reason, $hashes): void {
                if ($removed !== []) {
                    TopicRating::query()->withoutGlobalScopes()->forSubject($subject)->where('user_id', $userId)->whereIn('topic_id', $removed)->delete();
                }

                foreach ($valid as $topicId => $rating) {
                    $existing = TopicRating::query()
                        ->withoutGlobalScopes()
                        ->forSubject($subject)
                        ->where('user_id', $userId)
                        ->where('topic_id', $topicId)
                        ->lockForUpdate()
                        ->first();

                    if ($existing !== null) {
                        $existing->forceFill(['value' => $rating['value'], 'scale_max' => $rating['scale_max'], 'counted' => $counted, 'uncounted_reason' => $reason] + $hashes)->save();

                        continue;
                    }

                    TopicRating::query()->create([
                        'reviewable_type' => $subject->getMorphClass(),
                        'reviewable_id' => $subject->getKey(),
                        'user_id' => $userId,
                        'topic_id' => $topicId,
                        'value' => $rating['value'],
                        'scale_max' => $rating['scale_max'],
                        'counted' => $counted,
                        'uncounted_reason' => $reason,
                    ] + $hashes);
                }
            }, 3);
        };

        // Two first scores for the same member and topic can pass the existence check at the
        // same time; the loser hits the unique index, so retry that one time (the row is then
        // there and the second attempt updates it).
        try {
            $save();
        } catch (UniqueConstraintViolationException) {
            $save();
        }

        $this->refresh($subject);
        $own = $this->forUser($subject, $userId);
        $this->events->dispatch(new TopicRatingsSaved($subject, $userId, $own, array_values(array_diff($removed, array_keys($own))), $counted));

        return $own;
    }

    /**
     * The member's scores of the subject: [topic id => value].
     *
     * @return array<int, int>
     */
    public function forUser(Model $subject, int $userId): array
    {
        return TopicRating::query()->forSubject($subject)->where('user_id', $userId)->orderBy('topic_id')
            ->pluck('value', 'topic_id')->mapWithKeys(fn (mixed $value, mixed $topicId): array => [(int) $topicId => (int) $value])->all();
    }

    /**
     * Stores a score from a host importer (no request, no RiskSignals, no event), keeping
     * the original timestamps. Idempotent per subject, member and topic: a second import
     * overwrites the value. The topic must exist; the value must fit `$scaleMax`. Pass
     * `$refresh = false` in a bulk import and refresh the aggregates once per subject
     * afterwards (RefreshRatingAggregates::dispatch(type, id)).
     *
     * @throws ValidationException
     */
    public function import(int $userId, Model&Reviewable $subject, int $topicId, int $value, int $scaleMax, ?DateTimeInterface $ratedAt = null, bool $counted = true, bool $refresh = true): TopicRating
    {
        if (! RatingTopic::query()->whereKey($topicId)->exists()) {
            throw ValidationException::withMessages(['topic_id' => __('ratings-and-reviews::messages.topic_unknown')]);
        }

        if ($scaleMax < 2 || $value < 1 || $value > $scaleMax) {
            throw ValidationException::withMessages(['value' => __('ratings-and-reviews::messages.topic_value', ['max' => $scaleMax])]);
        }

        $at = $ratedAt ?? now();
        $rating = TopicRating::query()->updateOrCreate(
            ['reviewable_type' => $subject->getMorphClass(), 'reviewable_id' => $subject->getKey(), 'user_id' => $userId, 'topic_id' => $topicId],
            ['value' => $value, 'scale_max' => $scaleMax, 'counted' => $counted, 'uncounted_reason' => $counted ? null : 'imported', 'created_at' => $at, 'updated_at' => $at],
        );

        if ($refresh) {
            $this->refresh($subject);
        }

        return $rating;
    }

    private function refresh(Model $subject): void
    {
        RefreshRatingAggregates::dispatch($subject->getMorphClass(), (string) $subject->getKey());
    }
}

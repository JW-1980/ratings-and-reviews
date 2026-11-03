<?php

namespace RatingsAndReviews\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use RatingsAndReviews\Contracts\Reviewable;
use RatingsAndReviews\Models\RatingAggregate;
use RatingsAndReviews\Services\RatingAggregator;

/**
 * Recomputes the stored aggregates of one subject. Unique per subject until it starts
 * processing, so a burst of reviews queues one job, while a review arriving during the run
 * still queues the next one (and is not lost). Dispatched after the surrounding transaction
 * commits.
 */
class RefreshRatingAggregates implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 3;

    public function __construct(
        public string $reviewableType,
        public string $reviewableId,
    ) {
        $this->afterCommit();
    }

    public function uniqueId(): string
    {
        return $this->reviewableType.':'.$this->reviewableId;
    }

    public function handle(RatingAggregator $aggregator): void
    {
        $class = Relation::getMorphedModel($this->reviewableType) ?? $this->reviewableType;
        $subject = class_exists($class) && is_subclass_of($class, Model::class) ? $class::query()->find($this->reviewableId) : null;

        if ($subject instanceof Reviewable) {
            $aggregator->refresh($subject);

            return;
        }

        RatingAggregate::query()
            ->where('reviewable_type', $this->reviewableType)
            ->where('reviewable_id', $this->reviewableId)
            ->delete();
    }
}

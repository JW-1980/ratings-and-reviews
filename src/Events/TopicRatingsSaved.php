<?php

namespace RatingsAndReviews\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Database\Eloquent\Model;

/**
 * A member saved their topic ratings of a subject without a review (Services\TopicRatings::rate).
 * `ratings` is the member's complete set afterwards: [topic id => value]; `removed` lists the
 * topics they cleared. `counted` false: kept out of the averages (RiskSignals).
 */
final readonly class TopicRatingsSaved implements ShouldDispatchAfterCommit
{
    /**
     * @param  array<int, int>  $ratings
     * @param  list<int>  $removed
     */
    public function __construct(
        public Model $subject,
        public int $userId,
        public array $ratings,
        public array $removed,
        public bool $counted,
    ) {}
}

<?php

namespace RatingsAndReviews\Support;

/**
 * The outcome of a vote toggle: the viewer's current value on the dimension (null when
 * withdrawn), whether it counts, and the fresh counters of the item.
 */
final readonly class VoteResult
{
    /**
     * @param  array<string, int>  $counts  counter column => value
     */
    public function __construct(
        public VoteDimension $dimension,
        public ?int $value,
        public bool $counted,
        public ?string $uncountedReason,
        public array $counts,
    ) {}
}

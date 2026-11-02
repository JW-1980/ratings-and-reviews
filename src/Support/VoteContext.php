<?php

namespace RatingsAndReviews\Support;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use RatingsAndReviews\Models\Vote;

/**
 * Everything RiskSignals needs to decide whether a new vote counts.
 */
final readonly class VoteContext
{
    /**
     * @param  Builder<Vote>  $existingVotes  The other votes on the same item and dimension (clone before use).
     */
    public function __construct(
        public Model $votable,
        public VoteDimension $dimension,
        public int $value,
        public ?int $userId,
        public string $voterKey,
        public ?string $networkHash,
        public ?string $deviceHash,
        public Builder $existingVotes,
    ) {}
}

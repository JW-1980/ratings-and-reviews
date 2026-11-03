<?php

namespace RatingsAndReviews\Support;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use RatingsAndReviews\Models\Vote;

/**
 * Everything RiskSignals needs to decide whether a new vote counts, or whether a stored vote
 * still counts when VoteService::recountForUsers() re-evaluates it (`vote` is then the
 * stored row and `existingVotes` the votes on the item and dimension cast before it).
 */
final readonly class VoteContext
{
    /**
     * @param  Builder<Vote>  $existingVotes  The other votes on the same item and dimension (clone before use).
     * @param  Vote|null  $vote  the stored vote being re-evaluated; null for a new vote
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
        public ?Vote $vote = null,
    ) {}

    /** Whether a stored vote is being re-evaluated (VoteService::recountForUsers()). */
    public function isReevaluation(): bool
    {
        return $this->vote !== null;
    }
}

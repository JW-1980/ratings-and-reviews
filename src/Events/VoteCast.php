<?php

namespace RatingsAndReviews\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Database\Eloquent\Model;
use RatingsAndReviews\Support\VoteDimension;

/**
 * A vote was cast, switched or withdrawn (`value` null). `counted` false: the vote is
 * stored but RiskSignals kept it out of the counters.
 */
final readonly class VoteCast implements ShouldDispatchAfterCommit
{
    public function __construct(
        public Model $votable,
        public VoteDimension $dimension,
        public ?int $value,
        public ?int $previousValue,
        public string $voterKey,
        public ?int $userId,
        public bool $counted,
        public ?string $uncountedReason = null,
    ) {}
}

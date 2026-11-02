<?php

namespace RatingsAndReviews\Support;

use RatingsAndReviews\Models\Poll;
use RatingsAndReviews\Models\PollOption;

/**
 * Everything RiskSignals needs to decide whether a new poll vote counts.
 */
final readonly class PollVoteContext
{
    public function __construct(
        public Poll $poll,
        public PollOption $option,
        public ?int $userId,
        public string $voterKey,
        public ?string $networkHash,
        public ?string $deviceHash,
    ) {}
}

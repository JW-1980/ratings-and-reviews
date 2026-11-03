<?php

namespace RatingsAndReviews\Support;

use RatingsAndReviews\Models\Poll;
use RatingsAndReviews\Models\PollOption;
use RatingsAndReviews\Models\PollVote;

/**
 * Everything RiskSignals needs to decide whether a new poll vote counts, or whether a stored
 * one still counts when PollService::recountForUsers() re-evaluates it (`vote` is then the
 * stored row).
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
        public ?PollVote $vote = null,
    ) {}

    /** Whether a stored poll vote is being re-evaluated (PollService::recountForUsers()). */
    public function isReevaluation(): bool
    {
        return $this->vote !== null;
    }
}

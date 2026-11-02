<?php

namespace RatingsAndReviews\Defaults;

use RatingsAndReviews\Contracts\RiskSignals;
use RatingsAndReviews\Support\ContributionContext;
use RatingsAndReviews\Support\PollVoteContext;
use RatingsAndReviews\Support\RiskVerdict;
use RatingsAndReviews\Support\VoteContext;

/**
 * Default: every vote and contribution counts and nothing is held.
 */
class AllowAllRiskSignals implements RiskSignals
{
    public function forVote(VoteContext $context): RiskVerdict
    {
        return RiskVerdict::counts();
    }

    public function forPollVote(PollVoteContext $context): RiskVerdict
    {
        return RiskVerdict::counts();
    }

    public function forContribution(ContributionContext $context): RiskVerdict
    {
        return RiskVerdict::counts();
    }
}

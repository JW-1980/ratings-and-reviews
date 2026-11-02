<?php

namespace RatingsAndReviews\Contracts;

use RatingsAndReviews\Support\ContributionContext;
use RatingsAndReviews\Support\RiskVerdict;
use RatingsAndReviews\Support\VoteContext;

/**
 * Decides whether a vote or contribution counts and whether a contribution must wait for
 * a moderator. Hosts implement it with jw-1980/device-id. Default: everything counts.
 */
interface RiskSignals
{
    /** A vote is never refused; an uncounted verdict stores it with `counted = false`. */
    public function forVote(VoteContext $context): RiskVerdict;

    /** `hold` makes the review or comment pending; `counted` false keeps it out of aggregates. */
    public function forContribution(ContributionContext $context): RiskVerdict;
}

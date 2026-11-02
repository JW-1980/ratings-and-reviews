<?php

namespace RatingsAndReviews\Contracts;

use RatingsAndReviews\Support\ContributionContext;
use RatingsAndReviews\Support\PollVoteContext;
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

    /** A poll vote is never refused either; an uncounted verdict keeps it out of the results. */
    public function forPollVote(PollVoteContext $context): RiskVerdict;

    /**
     * `hold` makes the review or comment pending. `counted` false keeps a review out of the
     * rating aggregates; an uncounted comment is shown as usual but does not count as a
     * contribution (ContributionCounter, trust levels, the digest totals and top comments),
     * and hosts read `counted` on the model before awarding points on CommentPosted.
     */
    public function forContribution(ContributionContext $context): RiskVerdict;
}

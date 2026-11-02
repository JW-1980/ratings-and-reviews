<?php

namespace RatingsAndReviews\Contracts;

/**
 * Counts a user's approved contributions, for trust levels.
 */
interface ContributionCounter
{
    public function approvedContributions(int $userId): int;
}

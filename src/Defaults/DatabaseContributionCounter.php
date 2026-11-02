<?php

namespace RatingsAndReviews\Defaults;

use RatingsAndReviews\Contracts\ContributionCounter;
use RatingsAndReviews\Models\Comment;
use RatingsAndReviews\Models\Review;

/**
 * Default: approved, counted reviews plus published, counted comments in the package tables.
 * Placeholders of removed comments and contributions RiskSignals marked uncounted are left
 * out, so they cannot unlock trust levels.
 */
class DatabaseContributionCounter implements ContributionCounter
{
    public function approvedContributions(int $userId): int
    {
        return Review::query()->approved()->where('counted', true)->where('user_id', $userId)->count()
            + Comment::query()->published()->where('counted', true)->where('user_id', $userId)->withContent()->count();
    }
}

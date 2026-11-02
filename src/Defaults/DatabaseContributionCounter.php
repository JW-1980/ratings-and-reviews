<?php

namespace RatingsAndReviews\Defaults;

use RatingsAndReviews\Contracts\ContributionCounter;
use RatingsAndReviews\Models\Comment;
use RatingsAndReviews\Models\Review;

/**
 * Default: approved reviews plus approved comments (placeholders of removed comments excluded)
 * in the package tables.
 */
class DatabaseContributionCounter implements ContributionCounter
{
    public function approvedContributions(int $userId): int
    {
        return Review::query()->approved()->where('user_id', $userId)->count()
            + Comment::query()->approved()->where('user_id', $userId)->whereNull('author_removed_at')->count();
    }
}

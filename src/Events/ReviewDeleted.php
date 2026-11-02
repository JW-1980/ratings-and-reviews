<?php

namespace RatingsAndReviews\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use RatingsAndReviews\Models\Review;

/** A review was (soft) deleted by its author or a moderator (null: the author). */
final readonly class ReviewDeleted implements ShouldDispatchAfterCommit
{
    public function __construct(
        public Review $review,
        public int|string|null $moderatorId = null,
    ) {}
}

<?php

namespace RatingsAndReviews\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use RatingsAndReviews\Models\Review;

/** A review became publicly visible for the first time (points, notifications, followers). */
final readonly class ReviewPublished implements ShouldDispatchAfterCommit
{
    public function __construct(public Review $review) {}
}

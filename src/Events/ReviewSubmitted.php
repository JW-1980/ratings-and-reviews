<?php

namespace RatingsAndReviews\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use RatingsAndReviews\Models\Review;

/** A review was submitted or resubmitted (any resulting status). */
final readonly class ReviewSubmitted implements ShouldDispatchAfterCommit
{
    public function __construct(
        public Review $review,
        public bool $isResubmission = false,
    ) {}
}

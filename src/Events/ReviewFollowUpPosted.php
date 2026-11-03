<?php

namespace RatingsAndReviews\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use RatingsAndReviews\Models\Review;
use RatingsAndReviews\Models\ReviewFollowUp;

/**
 * The author added a follow-up to their review. `sentBackToModeration`: its text was held,
 * so the review (with the follow-up) waits for a moderator again.
 */
final readonly class ReviewFollowUpPosted implements ShouldDispatchAfterCommit
{
    public function __construct(
        public ReviewFollowUp $followUp,
        public Review $review,
        public bool $sentBackToModeration = false,
    ) {}
}

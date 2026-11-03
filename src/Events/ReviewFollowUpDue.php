<?php

namespace RatingsAndReviews\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use RatingsAndReviews\Models\Review;

/**
 * The author of this approved review can now add a follow-up (the section's
 * `review_follow_up_after_days` have passed); the host sends the invitation. Fired once per
 * review by rr:send-review-invitations (`follow_up_invited_at`).
 */
final readonly class ReviewFollowUpDue implements ShouldDispatchAfterCommit
{
    public function __construct(public Review $review) {}
}

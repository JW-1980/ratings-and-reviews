<?php

namespace RatingsAndReviews\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use RatingsAndReviews\Models\Review;
use RatingsAndReviews\Support\ContentStatus;

/** A moderator changed the status of a review. */
final readonly class ReviewModerated implements ShouldDispatchAfterCommit
{
    public function __construct(
        public Review $review,
        public ContentStatus $from,
        public ContentStatus $to,
        public int|string $moderatorId,
        public ?string $note = null,
    ) {}
}

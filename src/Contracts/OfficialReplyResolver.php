<?php

namespace RatingsAndReviews\Contracts;

use RatingsAndReviews\Models\Review;
use RatingsAndReviews\Support\OfficialReply;

/**
 * Optional: the official reply to a review (for example the brand's or the shop's answer),
 * owned and stored by the host. Shown as `official_reply` on the review resource. Batched,
 * so one list of reviews costs one lookup. Default: switched off (the key is absent).
 */
interface OfficialReplyResolver
{
    public function isEnabled(): bool;

    /**
     * @param  list<Review>  $reviews
     * @return array<int, OfficialReply|null> keyed by review id; a review without a reply may be missing or null
     */
    public function repliesFor(array $reviews): array;
}

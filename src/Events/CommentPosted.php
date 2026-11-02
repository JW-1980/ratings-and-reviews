<?php

namespace RatingsAndReviews\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use RatingsAndReviews\Models\Comment;

/**
 * A comment became publicly visible for the first time: on posting when it is approved at
 * once, or on its first approval by a moderator.
 */
final readonly class CommentPosted implements ShouldDispatchAfterCommit
{
    public function __construct(public Comment $comment) {}
}

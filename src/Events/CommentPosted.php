<?php

namespace RatingsAndReviews\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use RatingsAndReviews\Models\Comment;

/**
 * A comment became publicly visible for the first time: on posting when it is approved at
 * once, or on its first approval by a moderator.
 *
 * `recipients` names who should be told (the host sends the notification): the author of
 * the comment it answers (`reply`) or, for a top-level comment, the discussion owner
 * (HasDiscussionOwner, `owner`); never the comment's author and nobody in a block relation
 * with them (BlockList).
 */
final readonly class CommentPosted implements ShouldDispatchAfterCommit
{
    /**
     * @param  list<array{user_id: int, reason: string}>  $recipients
     */
    public function __construct(public Comment $comment, public array $recipients = []) {}
}

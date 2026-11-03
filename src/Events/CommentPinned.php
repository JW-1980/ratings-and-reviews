<?php

namespace RatingsAndReviews\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use RatingsAndReviews\Models\Comment;

/**
 * A top-level comment was pinned to the top of its discussion (`pinned` true) or unpinned.
 * `unpinnedId` is the comment that lost its pin because this one took it, if any.
 */
final readonly class CommentPinned implements ShouldDispatchAfterCommit
{
    public function __construct(
        public Comment $comment,
        public bool $pinned,
        public int|string $byUserId,
        public ?int $unpinnedId = null,
    ) {}
}

<?php

namespace RatingsAndReviews\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use RatingsAndReviews\Models\Comment;

/**
 * A comment was deleted. `placeholder` true: the author removed it while it had replies, so
 * it stays as a placeholder. `deletedIds` are every comment that was soft-deleted (a
 * moderator removes the whole subtree). `moderatorId` null: the author.
 */
final readonly class CommentDeleted implements ShouldDispatchAfterCommit
{
    /**
     * @param  list<int>  $deletedIds
     */
    public function __construct(
        public Comment $comment,
        public array $deletedIds,
        public bool $placeholder,
        public int|string|null $moderatorId = null,
    ) {}
}

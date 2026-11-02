<?php

namespace RatingsAndReviews\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use RatingsAndReviews\Models\Comment;
use RatingsAndReviews\Support\ContentStatus;

/** A moderator changed the status of a comment. */
final readonly class CommentModerated implements ShouldDispatchAfterCommit
{
    public function __construct(
        public Comment $comment,
        public ContentStatus $from,
        public ContentStatus $to,
        public int|string $moderatorId,
        public ?string $note = null,
    ) {}
}

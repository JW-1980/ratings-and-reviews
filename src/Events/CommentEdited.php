<?php

namespace RatingsAndReviews\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use RatingsAndReviews\Models\Comment;
use RatingsAndReviews\Models\CommentRevision;

/** A comment's text changed; the revision holds the previous text. */
final readonly class CommentEdited implements ShouldDispatchAfterCommit
{
    public function __construct(
        public Comment $comment,
        public CommentRevision $revision,
        public int|string $editorId,
        public bool $sentBackToModeration = false,
    ) {}
}

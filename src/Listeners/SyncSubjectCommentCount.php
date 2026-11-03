<?php

namespace RatingsAndReviews\Listeners;

use RatingsAndReviews\Events\CommentDeleted;
use RatingsAndReviews\Events\CommentEdited;
use RatingsAndReviews\Events\CommentModerated;
use RatingsAndReviews\Events\CommentPosted;
use RatingsAndReviews\Services\CommentCounts;

/**
 * Calls SyncsCommentCount on the subject after a comment event that can change the number of
 * public comments (the events are dispatched after the commit).
 */
class SyncSubjectCommentCount
{
    public function __construct(private readonly CommentCounts $counts) {}

    public function handle(CommentPosted|CommentDeleted|CommentModerated|CommentEdited $event): void
    {
        if ($event instanceof CommentEdited && ! $event->sentBackToModeration) {
            return;
        }

        $comment = $event->comment;
        $this->counts->syncByKey($comment->commentable_type, $comment->commentable_id);
    }
}

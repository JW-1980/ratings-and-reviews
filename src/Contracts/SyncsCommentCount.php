<?php

namespace RatingsAndReviews\Contracts;

/**
 * Optional, on a Commentable subject that keeps its own comment counter. The package calls
 * it after the transaction commits whenever the number of public comments may have changed
 * (CommentPosted, CommentEdited sent back to moderation, CommentModerated, CommentDeleted and
 * erasure withhold/restore/purge), with the number of published comments that still have
 * content (no author-removed or erased placeholders, nothing withheld). An exception is
 * reported and does not undo the change.
 */
interface SyncsCommentCount
{
    public function syncCommentCount(int $publicComments): void;
}

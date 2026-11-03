<?php

namespace RatingsAndReviews\Contracts;

/**
 * Optional, on a Commentable subject: the member who owns the discussion (the author of a
 * post, the owner of a group board). The owner may pin a top-level comment and is named in
 * CommentPosted::$recipients for new top-level comments (not for their own, nor across a
 * block). The package's Review returns its author.
 */
interface HasDiscussionOwner
{
    public function discussionOwnerId(): ?int;
}

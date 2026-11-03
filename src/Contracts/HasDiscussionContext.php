<?php

namespace RatingsAndReviews\Contracts;

/**
 * Optional, on a Commentable subject: the page the subject itself lives on (a group, a
 * board, a question) for "back to" links, when the discussion is shown elsewhere. Exposed as
 * `meta.subject.context_url` of the comment list. Without it: discussionUrl().
 */
interface HasDiscussionContext
{
    public function discussionContextUrl(): ?string;
}

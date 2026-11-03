<?php

namespace RatingsAndReviews\Contracts;

/**
 * Optional, on a Commentable and/or Reviewable subject: whether comments (with the replies
 * beneath them) and reviews by members in a block relation with the viewer are left out for
 * that viewer (BlockList::blockedWith()). Without it nothing is hidden; replies and votes
 * across a block are refused either way.
 */
interface HidesBlockedMembers
{
    public function hidesBlockedMembers(): bool;
}

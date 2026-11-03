<?php

namespace RatingsAndReviews\Contracts;

/**
 * The host's member blocks. A block is a relation between two members in either direction.
 * Enforced by the package: no reply to (or quote of) a blocked member's comment, no reply to
 * a blocked member's review, no vote or poll vote on a blocked member's content, nobody in a
 * block relation named in CommentPosted::$recipients, and, where the subject implements
 * HidesBlockedMembers, their comments and reviews left out for the viewer.
 * Default: no blocks (isEnabled() is false).
 */
interface BlockList
{
    public function isEnabled(): bool;

    /** Whether either member blocked the other. */
    public function isBlocked(int $userId, int $otherUserId): bool;

    /**
     * Everyone in a block relation with the member, in either direction.
     *
     * @return list<int>
     */
    public function blockedWith(int $userId): array;
}

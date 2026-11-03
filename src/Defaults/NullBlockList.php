<?php

namespace RatingsAndReviews\Defaults;

use RatingsAndReviews\Contracts\BlockList;

/** No blocks. */
class NullBlockList implements BlockList
{
    public function isEnabled(): bool
    {
        return false;
    }

    public function isBlocked(int $userId, int $otherUserId): bool
    {
        return false;
    }

    public function blockedWith(int $userId): array
    {
        return [];
    }
}

<?php

namespace RatingsAndReviews\Tests\Fixtures;

use RatingsAndReviews\Contracts\BlockList;

/** Blocks held in memory: pairs of member ids, either direction. */
class ArrayBlockList implements BlockList
{
    /** @var list<array{0: int, 1: int}> */
    public array $pairs = [];

    public int $lookups = 0;

    public function block(int $blocker, int $blocked): void
    {
        $this->pairs[] = [$blocker, $blocked];
    }

    public function isEnabled(): bool
    {
        return true;
    }

    public function isBlocked(int $userId, int $otherUserId): bool
    {
        return in_array($otherUserId, $this->blockedWith($userId), true);
    }

    public function blockedWith(int $userId): array
    {
        $this->lookups++;
        $ids = [];

        foreach ($this->pairs as [$a, $b]) {
            if ($a === $userId) {
                $ids[] = $b;
            } elseif ($b === $userId) {
                $ids[] = $a;
            }
        }

        return array_values(array_unique($ids));
    }
}

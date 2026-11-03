<?php

namespace RatingsAndReviews\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

/**
 * Services\Erasure withheld, restored or purged a user's package data. `counts` holds the
 * number of rows affected per kind (see the Erasure method that ran).
 */
final readonly class UserErasureProcessed implements ShouldDispatchAfterCommit
{
    public const WITHHOLD = 'withhold';

    public const RESTORE = 'restore';

    public const PURGE = 'purge';

    /**
     * @param  array<string, int>  $counts
     */
    public function __construct(
        public int $userId,
        public string $action,
        public array $counts,
    ) {}
}

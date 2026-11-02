<?php

namespace RatingsAndReviews\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Database\Eloquent\Model;

/** A user asked to put a subject on their list. (Fired by the want-to-try feature.) */
final readonly class WantToTryRequested implements ShouldDispatchAfterCommit
{
    public function __construct(
        public int|string $userId,
        public Model $subject,
        public ?Model $source = null,
    ) {}
}

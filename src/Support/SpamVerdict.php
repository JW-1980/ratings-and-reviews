<?php

namespace RatingsAndReviews\Support;

/**
 * The outcome of the SpamGuard: hard signals refuse, soft signals hold.
 */
final readonly class SpamVerdict
{
    /**
     * @param  list<string>  $reasons
     */
    public function __construct(
        public bool $refuse,
        public bool $hold,
        public array $reasons,
    ) {}

    public static function clean(): self
    {
        return new self(false, false, []);
    }
}

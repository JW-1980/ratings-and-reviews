<?php

namespace RatingsAndReviews\Support;

/**
 * Whether a vote or contribution counts. A vote is never refused: it is stored with
 * `counted = false` and the reason instead.
 */
final readonly class RiskVerdict
{
    public function __construct(
        public bool $counted = true,
        public ?string $uncountedReason = null,
        public bool $hold = false,
        public ?string $holdReason = null,
    ) {}

    public static function counts(): self
    {
        return new self;
    }

    public static function uncounted(string $reason): self
    {
        return new self(counted: false, uncountedReason: $reason);
    }

    public static function held(string $reason, bool $counted = true): self
    {
        return new self(counted: $counted, hold: true, holdReason: $reason);
    }
}

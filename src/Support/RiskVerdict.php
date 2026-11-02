<?php

namespace RatingsAndReviews\Support;

/**
 * Whether a vote or contribution counts. A vote is never refused: it is stored with
 * `counted = false` and the reason instead.
 *
 * `meta` (votes and poll votes only): host data the package stores with the new vote in
 * `rr_votes.meta` / `rr_poll_votes.meta`, in the same transaction, and never reads. Ignored
 * for contributions.
 */
final readonly class RiskVerdict
{
    /**
     * @param  array<string, mixed>|null  $meta
     */
    public function __construct(
        public bool $counted = true,
        public ?string $uncountedReason = null,
        public bool $hold = false,
        public ?string $holdReason = null,
        public ?array $meta = null,
    ) {}

    /**
     * The same verdict with host data to store with the vote.
     *
     * @param  array<string, mixed>  $meta
     */
    public function withMeta(array $meta): self
    {
        return new self($this->counted, $this->uncountedReason, $this->hold, $this->holdReason, $meta);
    }

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

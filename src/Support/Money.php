<?php

namespace RatingsAndReviews\Support;

final readonly class Money
{
    public function __construct(
        public int $cents,
        public string $currency,
    ) {}
}

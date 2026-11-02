<?php

namespace RatingsAndReviews\Defaults;

use Illuminate\Contracts\Auth\Authenticatable;
use RatingsAndReviews\Contracts\CardResolver;
use RatingsAndReviews\Support\ProductCard;

/** Default: product cards are switched off. */
class NullCardResolver implements CardResolver
{
    public function isEnabled(): bool
    {
        return false;
    }

    public function resolve(string $type, string $id, ?Authenticatable $viewer): ?ProductCard
    {
        return null;
    }
}

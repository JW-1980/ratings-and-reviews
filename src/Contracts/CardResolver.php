<?php

namespace RatingsAndReviews\Contracts;

use Illuminate\Contracts\Auth\Authenticatable;
use RatingsAndReviews\Support\ProductCard;

/**
 * Optional: renders a product card for `type:id` in the editor.
 */
interface CardResolver
{
    public function isEnabled(): bool;

    public function resolve(string $type, string $id, ?Authenticatable $viewer): ?ProductCard;
}

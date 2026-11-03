<?php

namespace RatingsAndReviews\Defaults;

use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use RatingsAndReviews\Contracts\PriceAtReviewResolver;
use RatingsAndReviews\Support\Money;

/** Default: "price at review" is switched off. */
class NullPriceAtReviewResolver implements PriceAtReviewResolver
{
    public function isEnabled(): bool
    {
        return false;
    }

    public function priceAt(Model $subject, DateTimeInterface $at): ?Money
    {
        return null;
    }
}

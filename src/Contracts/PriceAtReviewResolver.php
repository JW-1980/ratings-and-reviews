<?php

namespace RatingsAndReviews\Contracts;

use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use RatingsAndReviews\Support\Money;

/**
 * Optional: the price of a subject on a date, stored with the review ("price then").
 */
interface PriceAtReviewResolver
{
    public function isEnabled(): bool;

    public function priceAt(Model $subject, DateTimeInterface $at): ?Money;
}

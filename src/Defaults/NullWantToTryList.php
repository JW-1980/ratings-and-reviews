<?php

namespace RatingsAndReviews\Defaults;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use RatingsAndReviews\Contracts\WantToTryList;

/** Default: "want to try" is switched off. */
class NullWantToTryList implements WantToTryList
{
    public function isEnabled(): bool
    {
        return false;
    }

    public function add(Authenticatable $user, Model $subject, ?Model $source = null): bool
    {
        return false;
    }
}

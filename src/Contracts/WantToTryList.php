<?php

namespace RatingsAndReviews\Contracts;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;

/**
 * Optional: puts a subject on the user's list ("Wil ik proberen").
 */
interface WantToTryList
{
    public function isEnabled(): bool;

    /** @return bool Whether the subject was added (false when it was already on the list). */
    public function add(Authenticatable $user, Model $subject, ?Model $source = null): bool;
}

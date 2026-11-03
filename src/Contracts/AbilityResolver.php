<?php

namespace RatingsAndReviews\Contracts;

use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Maps the package abilities (RatingsAndReviews\Support\Ability) to the host's RBAC.
 */
interface AbilityResolver
{
    public function allows(?Authenticatable $user, string $ability, mixed $subject = null): bool;
}

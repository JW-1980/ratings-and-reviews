<?php

namespace RatingsAndReviews\Defaults;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use RatingsAndReviews\Contracts\AttributionResolver;

/** Posting as someone else is switched off. */
class NullAttributionResolver implements AttributionResolver
{
    public function isEnabled(): bool
    {
        return false;
    }

    public function allowedFor(Authenticatable $user, Model $subject): array
    {
        return [];
    }
}

<?php

namespace RatingsAndReviews\Tests\Fixtures;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use RatingsAndReviews\Contracts\AttributionResolver;
use RatingsAndReviews\Support\Attribution;

/** The brands each member may post as, filled by the test. */
class StaticAttributions implements AttributionResolver
{
    /** @var array<int, list<Attribution>> */
    public array $byUser = [];

    public function isEnabled(): bool
    {
        return true;
    }

    public function allowedFor(Authenticatable $user, Model $subject): array
    {
        return $this->byUser[(int) $user->getAuthIdentifier()] ?? [];
    }
}

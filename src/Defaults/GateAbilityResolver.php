<?php

namespace RatingsAndReviews\Defaults;

use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Container\Container;
use RatingsAndReviews\Contracts\AbilityResolver;

/**
 * Asks the host's Gate. An ability the host never defined is denied, and so is every
 * ability for a guest.
 */
class GateAbilityResolver implements AbilityResolver
{
    public function __construct(private readonly Container $container) {}

    public function allows(?Authenticatable $user, string $ability, mixed $subject = null): bool
    {
        if ($user === null || ! $this->container->bound(Gate::class)) {
            return false;
        }

        /** @var Gate $gate */
        $gate = $this->container->make(Gate::class);

        if (! $gate->has($ability)) {
            return false;
        }

        return $gate->forUser($user)->check($ability, $subject === null ? [] : [$subject]);
    }
}

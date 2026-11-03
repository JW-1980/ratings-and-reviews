<?php

namespace RatingsAndReviews\Tests\Fixtures;

use Illuminate\Contracts\Auth\Authenticatable;
use RatingsAndReviews\Contracts\KindAwareParticipationRules;
use RatingsAndReviews\Contracts\ParticipationRules;

/**
 * A host subject that decides participation per kind (KindAwareParticipationRules), as a
 * shop branch would: customers review, the branch's staff reply to reviews. It also has
 * ParticipationRules, whose canParticipate() must then no longer be asked. The rules read
 * static lists the tests fill (reset in setUp).
 */
class Branch extends Product implements KindAwareParticipationRules, ParticipationRules
{
    /** @var array<string, list<int>> kind => the members allowed (a kind missing: everyone) */
    public static array $allowed = [];

    /** @var list<string> every kind asked, in order */
    public static array $asked = [];

    public static function reset(): void
    {
        self::$allowed = [];
        self::$asked = [];
    }

    public function canParticipateIn(Authenticatable $user, string $kind): bool
    {
        self::$asked[] = $kind;

        return ! array_key_exists($kind, self::$allowed) || in_array((int) $user->getAuthIdentifier(), self::$allowed[$kind], true);
    }

    public function canParticipate(Authenticatable $user): bool
    {
        throw new \LogicException('canParticipateIn() decides for a kind-aware subject.');
    }

    public function holdsContributionFrom(Authenticatable $author): ?bool
    {
        return null;
    }
}

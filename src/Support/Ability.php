<?php

namespace RatingsAndReviews\Support;

/**
 * The abilities the package asks the host's RBAC about through the AbilityResolver.
 */
final class Ability
{
    public const MODERATE = 'rr.moderate';

    public const MANAGE_SETTINGS = 'rr.manage-settings';

    public const MANAGE_TOPICS = 'rr.manage-topics';

    /**
     * @return list<string>
     */
    public static function all(): array
    {
        return [self::MODERATE, self::MANAGE_SETTINGS, self::MANAGE_TOPICS];
    }
}

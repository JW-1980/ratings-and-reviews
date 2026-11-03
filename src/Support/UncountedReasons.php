<?php

namespace RatingsAndReviews\Support;

use Illuminate\Support\Facades\Lang;

/**
 * Labels and visibility of the reasons a review does not count (`rr_reviews.uncounted_reason`),
 * from config `reviews.uncounted_reasons`: key => ['nl' => …, 'en' => …, 'public' => bool,
 * 'keep_on_edit' => bool]. The label is the entry's text in the current locale, else the
 * package translation `enums.uncounted_reason.<key>`, else the generic label; there is no
 * other fallback language.
 */
final class UncountedReasons
{
    /**
     * @return array<string, mixed>
     */
    private static function entry(?string $reason): array
    {
        if ($reason === null || $reason === '') {
            return [];
        }

        $entry = config('ratings-and-reviews.reviews.uncounted_reasons.'.$reason);

        return is_array($entry) ? $entry : [];
    }

    public static function label(?string $reason): string
    {
        $configured = self::entry($reason)[app()->getLocale()] ?? null;

        if (is_string($configured) && trim($configured) !== '') {
            return trim($configured);
        }

        $key = 'ratings-and-reviews::enums.uncounted_reason.'.$reason;

        return (string) ($reason !== null && $reason !== '' && Lang::has($key) ? __($key) : __('ratings-and-reviews::enums.uncounted_reason.default'));
    }

    /** Whether everyone may see the reason on the review (a disclosure such as a free sample). */
    public static function isPublic(?string $reason): bool
    {
        return (bool) (self::entry($reason)['public'] ?? false);
    }

    /** Whether an edit by the author keeps the review uncounted (a host decision, not a check). */
    public static function keepsOnEdit(?string $reason): bool
    {
        return (bool) (self::entry($reason)['keep_on_edit'] ?? false);
    }

    /**
     * What a viewer may see of an uncounted review: everything for the author and moderators,
     * only a public reason for others; null when it counts or the viewer may not see why.
     *
     * @return array{uncounted_reason: string|null, uncounted_label: string, uncounted_public: bool}|null
     */
    public static function present(bool $counted, ?string $reason, bool $privileged): ?array
    {
        if ($counted) {
            return null;
        }

        $public = self::isPublic($reason);

        if (! $privileged && ! $public) {
            return null;
        }

        return ['uncounted_reason' => $reason, 'uncounted_label' => self::label($reason), 'uncounted_public' => $public];
    }
}

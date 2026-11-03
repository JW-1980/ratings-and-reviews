<?php

namespace RatingsAndReviews\Support;

use Closure;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Collection;
use InvalidArgumentException;

/**
 * Host extension points on the review and comment cards of the Blade components.
 *
 * A host registers what it wants to render at a position, usually in a service provider's
 * `boot()`:
 *
 *   CardExtensions::register('review.footer', fn (array $review, ?Authenticatable $viewer, mixed $preloaded) => view('reactions.bar', [...]), 'reactions');
 *   CardExtensions::preload('review.footer', fn (Collection $reviews, ?Authenticatable $viewer) => Reaction::countsFor($reviews->pluck('id')), 'reactions');
 *
 * A renderer gets the item as the API returns it (ReviewResource / CommentResource array, the
 * same array `brand-reply` gets), the viewer (null for a guest) and the result of the preload
 * with the same key (null without one). It returns an `Htmlable` (a View, an HtmlString) that
 * is printed as is, a string that is escaped, or null for nothing. A preload runs once per
 * rendered list with every item of that list, so a host needs one query per list instead of
 * one per card. The config list `card_extensions.views` adds host views the same way.
 *
 * The registrations live in a container singleton (CardExtensionRegistry), so they survive
 * requests under Octane like other boot-time registrations; registering the same key again
 * replaces the earlier one.
 */
final class CardExtensions
{
    public const REVIEW_META = 'review.meta';

    public const REVIEW_FOOTER = 'review.footer';

    public const COMMENT_META = 'comment.meta';

    public const COMMENT_FOOTER = 'comment.footer';

    /** @var list<string> */
    public const POSITIONS = [self::REVIEW_META, self::REVIEW_FOOTER, self::COMMENT_META, self::COMMENT_FOOTER];

    /**
     * Renders at `$position` on every card of that kind.
     *
     * @param  Closure(array<string, mixed>, ?Authenticatable, mixed): mixed|string  $renderer  a closure, or a view name
     * @param  string|null  $key  identifies the extension: the same key replaces it, and it pairs it with a preload
     */
    public static function register(string $position, Closure|string $renderer, ?string $key = null): void
    {
        self::registry()->register($position, $renderer, $key);
    }

    /**
     * Loads data for all items of a list in one go; the result is the third argument of the
     * renderers with the same key at that position.
     *
     * @param  Closure(Collection<int, array<string, mixed>>, ?Authenticatable): mixed  $loader
     */
    public static function preload(string $position, Closure $loader, ?string $key = null): void
    {
        self::registry()->preload($position, $loader, $key);
    }

    /** Removes every registration at `$position`, or everywhere (tests, feature flags). */
    public static function forget(?string $position = null): void
    {
        self::registry()->forget($position);
    }

    /**
     * The renderer of one list of cards (`review` or `comment`), with the preloads run lazily
     * once for its items.
     *
     * @param  iterable<array<string, mixed>>  $items
     */
    public static function forList(string $kind, iterable $items, ?Authenticatable $viewer): CardExtensionList
    {
        return self::registry()->forList($kind, $items, $viewer);
    }

    public static function registry(): CardExtensionRegistry
    {
        return app(CardExtensionRegistry::class);
    }

    /** @throws InvalidArgumentException for an unknown position */
    public static function assertPosition(string $position): void
    {
        if (! in_array($position, self::POSITIONS, true)) {
            throw new InvalidArgumentException("Unknown card extension position [{$position}]; use one of: ".implode(', ', self::POSITIONS).'.');
        }
    }

    /** `review` or `comment`: the kind of card a position belongs to. */
    public static function kindOf(string $position): string
    {
        self::assertPosition($position);

        return explode('.', $position, 2)[0];
    }
}

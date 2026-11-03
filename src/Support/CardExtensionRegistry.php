<?php

namespace RatingsAndReviews\Support;

use Closure;
use Illuminate\Contracts\Auth\Authenticatable;
use InvalidArgumentException;

/**
 * The state behind `Support\CardExtensions` (a container singleton): the renderers and preloads
 * per position. Host views from the config (`card_extensions.views`) are read when a list is
 * rendered, so a config change in a test or at runtime is seen without re-registering.
 */
final class CardExtensionRegistry
{
    /** @var array<string, list<array{key: string|null, renderer: Closure|string}>> */
    private array $renderers = [];

    /** @var array<string, array<string, Closure>> */
    private array $preloads = [];

    public function register(string $position, Closure|string $renderer, ?string $key = null): void
    {
        CardExtensions::assertPosition($position);

        if (is_string($renderer) && trim($renderer) === '') {
            throw new InvalidArgumentException('A card extension view name cannot be empty.');
        }

        $entries = $this->renderers[$position] ?? [];

        if ($key !== null) {
            $entries = array_values(array_filter($entries, fn (array $entry): bool => $entry['key'] !== $key));
        }

        $entries[] = ['key' => $key, 'renderer' => $renderer];
        $this->renderers[$position] = $entries;
    }

    public function preload(string $position, Closure $loader, ?string $key = null): void
    {
        CardExtensions::assertPosition($position);
        $this->preloads[$position][$key ?? ''] = $loader;
    }

    public function forget(?string $position = null): void
    {
        if ($position === null) {
            $this->renderers = [];
            $this->preloads = [];

            return;
        }

        CardExtensions::assertPosition($position);
        unset($this->renderers[$position], $this->preloads[$position]);
    }

    /**
     * Every renderer at a position: the config views first, then the registrations in order.
     *
     * @return list<array{key: string|null, renderer: Closure|string}>
     */
    public function renderersAt(string $position): array
    {
        CardExtensions::assertPosition($position);
        $views = (array) (config('ratings-and-reviews.card_extensions.views', [])[$position] ?? []);
        $fromConfig = array_map(fn (string $view): array => ['key' => null, 'renderer' => $view], array_values(array_filter($views, fn (mixed $view): bool => is_string($view) && trim($view) !== '')));

        return [...$fromConfig, ...($this->renderers[$position] ?? [])];
    }

    /** @return array<string, Closure> keyed by the preload key ('' without one) */
    public function preloadsAt(string $position): array
    {
        CardExtensions::assertPosition($position);

        return $this->preloads[$position] ?? [];
    }

    /** @param  iterable<array<string, mixed>>  $items */
    public function forList(string $kind, iterable $items, ?Authenticatable $viewer): CardExtensionList
    {
        if (! in_array($kind, ['review', 'comment'], true)) {
            throw new InvalidArgumentException("Unknown card kind [{$kind}]; use review or comment.");
        }

        $this->assertConfiguredPositions();

        return new CardExtensionList($this, $kind, $items, $viewer);
    }

    /** A typo in `card_extensions.views` is a configuration error, not a silently empty card. */
    private function assertConfiguredPositions(): void
    {
        foreach (array_keys((array) config('ratings-and-reviews.card_extensions.views', [])) as $position) {
            CardExtensions::assertPosition((string) $position);
        }
    }
}

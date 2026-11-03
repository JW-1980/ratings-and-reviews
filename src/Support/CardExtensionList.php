<?php

namespace RatingsAndReviews\Support;

use Closure;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Collection;
use Illuminate\Support\HtmlString;
use InvalidArgumentException;
use Stringable;
use Throwable;
use UnexpectedValueException;

/**
 * The card extensions of one rendered list (the reviews of a page, or every comment of a
 * discussion page including its replies). The preloads of a position run once, the first
 * time a card renders that position, with all items of the list.
 *
 * A failing renderer or preload is reported (`report()`) and leaves that extension out of the
 * card, so a host feature cannot take down the review list; with `app.debug` on it is thrown.
 * When a preload fails, the renderers with its key are left out for this list (they would
 * otherwise query per card).
 */
final class CardExtensionList
{
    /** @var Collection<int, array<string, mixed>> */
    private Collection $items;

    /** @var array<string, array<string, array{ok: bool, value: mixed}>> */
    private array $preloaded = [];

    /** @param  iterable<array<string, mixed>>  $items */
    public function __construct(
        private readonly CardExtensionRegistry $registry,
        private readonly string $kind,
        iterable $items,
        private readonly ?Authenticatable $viewer,
    ) {
        $this->items = Collection::make($items)->filter(fn (mixed $item): bool => is_array($item) && isset($item['id']))->values();
    }

    /** Whether anything is registered at a position (the card can skip the wrapper). */
    public function has(string $position): bool
    {
        $this->assertKind($position);

        return $this->registry->renderersAt($position) !== [];
    }

    /**
     * The extensions of one card at `$position`, wrapped in an element the browser enhancement
     * finds (`data-rr-extension`), or an empty string when nothing renders.
     *
     * @param  array<string, mixed>  $item
     */
    public function render(string $position, array $item): HtmlString
    {
        $this->assertKind($position);
        $renderers = $this->registry->renderersAt($position);

        if ($renderers === []) {
            return new HtmlString('');
        }

        $preloaded = $this->preloadedAt($position);
        $parts = [];

        foreach ($renderers as $entry) {
            $key = $entry['key'] ?? '';
            $preload = $preloaded[$key] ?? ['ok' => true, 'value' => null];

            if (! $preload['ok']) {
                continue;
            }

            $html = $this->guard(fn (): string => $this->toHtml($this->invoke($entry['renderer'], $position, $item, $preload['value']), $position), '');

            if ($html !== '') {
                $parts[] = $html;
            }
        }

        if ($parts === []) {
            return new HtmlString('');
        }

        $tag = str_ends_with($position, '.meta') ? 'span' : 'div';
        $slot = substr($position, strpos($position, '.') + 1);

        return new HtmlString(sprintf(
            '<%1$s class="rr-extensions rr-extensions--%2$s" data-rr-extension="%3$s" data-rr-item-type="%4$s" data-rr-item-id="%5$s">%6$s</%1$s>',
            $tag,
            e($slot),
            e($position),
            e($this->kind),
            e((string) $item['id']),
            implode('', $parts),
        ));
    }

    /** @return array<string, array{ok: bool, value: mixed}> */
    private function preloadedAt(string $position): array
    {
        if (isset($this->preloaded[$position])) {
            return $this->preloaded[$position];
        }

        $results = [];

        foreach ($this->registry->preloadsAt($position) as $key => $loader) {
            $failed = false;
            $value = $this->guard(fn (): mixed => $loader($this->items, $this->viewer), null, $failed);
            $results[$key] = ['ok' => ! $failed, 'value' => $value];
        }

        return $this->preloaded[$position] = $results;
    }

    /** @param  array<string, mixed>  $item */
    private function invoke(Closure|string $renderer, string $position, array $item, mixed $preloaded): mixed
    {
        if ($renderer instanceof Closure) {
            return $renderer($item, $this->viewer, $preloaded);
        }

        return view($renderer, [
            'item' => $item,
            $this->kind => $item,
            'viewer' => $this->viewer,
            'preloaded' => $preloaded,
            'position' => $position,
        ]);
    }

    private function toHtml(mixed $output, string $position): string
    {
        return match (true) {
            $output === null => '',
            $output instanceof Htmlable => (string) $output->toHtml(),
            is_string($output), $output instanceof Stringable, is_int($output), is_float($output) => e((string) $output),
            default => throw new UnexpectedValueException("A card extension at [{$position}] returned ".get_debug_type($output).'; return an Htmlable, a string or null.'),
        };
    }

    /**
     * Runs host code: in debug mode a failure is thrown, otherwise reported and replaced.
     *
     * @template T
     *
     * @param  Closure(): T  $callback
     * @param  T  $fallback
     * @return T
     */
    private function guard(Closure $callback, mixed $fallback, bool &$failed = false): mixed
    {
        try {
            return $callback();
        } catch (Throwable $exception) {
            if (config('app.debug')) {
                throw $exception;
            }

            report($exception);
            $failed = true;

            return $fallback;
        }
    }

    private function assertKind(string $position): void
    {
        if (CardExtensions::kindOf($position) !== $this->kind) {
            throw new InvalidArgumentException("Position [{$position}] does not belong to a {$this->kind} card.");
        }
    }
}

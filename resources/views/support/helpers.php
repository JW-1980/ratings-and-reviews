<?php

/*
| View helpers of the Blade components. Not a view: components load it with
|   $rr = require view()->getFinder()->find('rr::support.helpers');
| It returns closures only (no global functions, no service provider changes).
|
| `api` runs a package JSON route in-process, as the browser would call it, so the server-
| rendered markup uses exactly the controllers, permissions and response shapes of the API
| (one source of truth). The sub-request carries the current user, session and cookies;
| middleware is not run (the read routes have none besides the route group's). A 403/404
| (subject not visible, optional feature switched off) yields null, so the component hides
| that part; other exceptions propagate. Results are cached per request.
*/

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\MessageBag;
use Illuminate\Support\ViewErrorBag;
use Illuminate\Validation\ValidationException;
use RatingsAndReviews\Models\Upload;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

$locale = fn (): string => str_replace('_', '-', (string) app()->getLocale());

$api = static function (string $name, array $parameters = [], array $query = []): ?array {
    $routeName = (string) config('ratings-and-reviews.routes.name', 'rr.').$name;
    $current = request();
    $cacheKey = 'rr.view-api:'.$routeName.':'.md5(serialize([$parameters, $query]));

    if ($current->attributes->has($cacheKey)) {
        return $current->attributes->get($cacheKey);
    }

    if (! config('ratings-and-reviews.routes.enabled', true) || ! Route::has($routeName)) {
        return null;
    }

    $route = Route::getRoutes()->getByName($routeName);
    $query = array_filter($query, fn (mixed $value): bool => $value !== null && $value !== '' && $value !== []);
    $sub = Request::create(route($routeName, $parameters, false), 'GET', $query, $current->cookies->all(), [], $current->server->all());
    $sub->headers->set('Accept', 'application/json');
    $sub->setUserResolver($current->getUserResolver());
    $sub->setRouteResolver(fn () => $route);

    if ($current->hasSession()) {
        $sub->setLaravelSession($current->session());
    }

    $app = app();
    $previous = $app->make('request');
    $app->instance('request', $sub);

    try {
        $route->bind($sub);
        $response = $route->run();
        $result = $response instanceof JsonResponse && $response->isSuccessful() ? (array) $response->getData(true) : null;
    } catch (ValidationException $exception) {
        $result = ['errors' => $exception->errors()];
    } catch (HttpExceptionInterface $exception) {
        if (! in_array($exception->getStatusCode(), [401, 403, 404], true)) {
            throw $exception;
        }

        $result = null;
    } finally {
        $app->instance('request', $previous);
    }

    $current->attributes->set($cacheKey, $result);

    return $result;
};

$number = static function (float|int|null $value, int $digits = 1) use ($locale): string {
    if ($value === null) {
        return '–';
    }

    if (class_exists(NumberFormatter::class)) {
        $formatter = new NumberFormatter($locale(), NumberFormatter::DECIMAL);
        $formatter->setAttribute(NumberFormatter::MIN_FRACTION_DIGITS, $digits);
        $formatter->setAttribute(NumberFormatter::MAX_FRACTION_DIGITS, $digits);

        return (string) $formatter->format($value);
    }

    $nl = str_starts_with($locale(), 'nl');

    return number_format((float) $value, $digits, $nl ? ',' : '.', $nl ? '.' : ',');
};

$money = static function (?array $price) use ($locale): string {
    if ($price === null || ! isset($price['cents'])) {
        return '';
    }

    $currency = (string) ($price['currency'] ?? 'EUR');

    if (class_exists(NumberFormatter::class)) {
        return (string) (new NumberFormatter($locale(), NumberFormatter::CURRENCY))->formatCurrency($price['cents'] / 100, $currency);
    }

    return number_format($price['cents'] / 100, 2, ',', '.').' '.$currency;
};

$date = static function (?string $iso, string $format = 'medium') use ($locale): string {
    if ($iso === null || $iso === '') {
        return '';
    }

    $time = Carbon::parse($iso)->locale($locale());

    return match ($format) {
        'relative' => $time->diffForHumans(),
        'month' => $time->isoFormat('MMM YYYY'),
        'long' => $time->isoFormat('LL'),
        'datetime' => $time->isoFormat('LLL'),
        default => $time->isoFormat('ll'),
    };
};

/** Query parameters of a component, namespaced so several components share one page URL. */
$query = static function (string $prefix, array $changes = []): string {
    $current = request()->query();

    foreach ($changes as $key => $value) {
        if ($value === null || $value === '' || $value === []) {
            unset($current[$prefix.$key]);
        } else {
            $current[$prefix.$key] = $value;
        }
    }

    $string = http_build_query($current);

    return request()->url().($string === '' ? '' : '?'.$string);
};

/** Settings for the browser enhancement: API base, locale, CSRF meta and the media prefixes. */
$clientConfig = static fn (): array => [
    'api' => url((string) config('ratings-and-reviews.routes.prefix', 'rr')),
    'locale' => $locale(),
    'mediaPrefixes' => array_values(array_unique([
        Upload::urlPrefix(),
        (string) config('ratings-and-reviews.stickers.url_prefix', '/storage/'),
    ])),
    'uploadTypes' => array_values((array) config('ratings-and-reviews.uploads.mime_types', [])),
    'reportDetailsRequiredFor' => array_values((array) config('ratings-and-reviews.reports.details_required_for', [])),
    'authenticated' => auth(config('ratings-and-reviews.routes.guard'))->check(),
];

/**
 * A URL of the package's no-JavaScript form routes (`web_routes.enabled`), or null when they
 * are off: the components then fall back to the host `action` (or JavaScript only).
 */
$webRoute = static function (string $name, array $parameters = []): ?string {
    if (! config('ratings-and-reviews.web_routes.enabled', false)) {
        return null;
    }

    $routeName = (string) config('ratings-and-reviews.web_routes.name', 'rr.web.').$name;

    return Route::has($routeName) ? route($routeName, $parameters) : null;
};

/**
 * Whether the flashed errors and old input belong to this form. The form routes flash
 * `rr_form` (`review`, `comment:root`, `comment:<parent id>`, `edit:<id>`, `report:<type>:<id>`,
 * …); a host route that does not is treated as the page's main form (`$main`).
 */
$isForm = static function (string $form, bool $main = false): bool {
    $flashed = session('rr_form');

    return $flashed === null ? $main : $flashed === $form;
};

/** The errors of one form: the package's named bag when the form routes used it, else the default bag. */
$formErrors = static function (string $bag): MessageBag {
    $errors = session('errors');

    if (! $errors instanceof ViewErrorBag) {
        return new MessageBag;
    }

    return $errors->hasBag($bag) ? $errors->getBag($bag) : $errors->getBag('default');
};

/**
 * Builds a reply tree from flat CommentResource arrays (`parent_id`), children oldest first;
 * replies whose parent is not in the list (or the root) are left out.
 *
 * @param  list<array<string, mixed>>  $flat
 * @return list<array<string, mixed>>
 */
$replyTree = static function (int $rootId, array $flat): array {
    $nodes = [];

    foreach ($flat as $item) {
        $nodes[$item['id']] ??= ['replies' => []] + $item;
        $nodes[$item['id']]['replies'] = [];
    }

    $children = [];

    foreach ($nodes as $id => $node) {
        $children[$node['parent_id'] ?? 0][] = $id;
    }

    $build = function (int $parent) use (&$build, &$children, $nodes): array {
        $ids = $children[$parent] ?? [];
        usort($ids, fn (int $a, int $b): int => [$nodes[$a]['created_at'] ?? '', $a] <=> [$nodes[$b]['created_at'] ?? '', $b]);

        return array_map(fn (int $id): array => ['replies' => $build($id)] + $nodes[$id], $ids);
    };

    return $build($rootId);
};

/** Every reply in a tree, depth first. */
$flattenReplies = static function (array $replies) use (&$flattenReplies): array {
    $flat = [];

    foreach ($replies as $reply) {
        $flat = [...$flat, ['replies' => []] + $reply, ...$flattenReplies($reply['replies'] ?? [])];
    }

    return $flat;
};

/** The signed-in user of the package guard, or null for a guest (card extensions get it). */
$viewer = static fn (): ?Illuminate\Contracts\Auth\Authenticatable => auth(config('ratings-and-reviews.routes.guard'))->user();

$reportReasons = static fn (): array => array_map(
    fn (string $reason): array => ['value' => $reason, 'label' => (string) __('ratings-and-reviews::enums.report_reason.'.$reason)],
    array_values((array) config('ratings-and-reviews.reports.reasons', [])),
);

return [
    'api' => $api,
    'number' => $number,
    'money' => $money,
    'date' => $date,
    'query' => $query,
    'clientConfig' => $clientConfig,
    'reportReasons' => $reportReasons,
    'locale' => $locale,
    'webRoute' => $webRoute,
    'isForm' => $isForm,
    'formErrors' => $formErrors,
    'replyTree' => $replyTree,
    'flattenReplies' => $flattenReplies,
    'viewer' => $viewer,
];

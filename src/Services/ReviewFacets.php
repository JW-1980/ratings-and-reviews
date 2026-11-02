<?php

namespace RatingsAndReviews\Services;

use Illuminate\Validation\ValidationException;

/**
 * Context facets of a review ("first time bought", "on offer"): groups of allowed values
 * from `reviews.facets`. A review stores at most one value per group as {group: value}.
 */
class ReviewFacets
{
    /**
     * @return array<string, list<string>>
     */
    public function groups(): array
    {
        $groups = [];

        foreach ((array) config('ratings-and-reviews.reviews.facets', []) as $group => $values) {
            if (! is_string($group) || preg_match('/^[a-z][a-z0-9_]{0,31}$/', $group) !== 1) {
                continue;
            }

            $clean = array_values(array_unique(array_filter((array) $values, fn (mixed $value): bool => is_string($value) && preg_match('/^[a-z][a-z0-9_]{0,31}$/', $value) === 1)));

            if ($clean !== []) {
                $groups[$group] = $clean;
            }
        }

        return $groups;
    }

    /**
     * @return array<string, string>|null
     *
     * @throws ValidationException on an unknown group or value
     */
    public function validate(mixed $input): ?array
    {
        if ($input === null || $input === []) {
            return null;
        }

        if (! is_array($input)) {
            throw ValidationException::withMessages(['facets' => __('ratings-and-reviews::messages.facet_invalid')]);
        }

        $groups = $this->groups();
        $facets = [];

        foreach ($input as $group => $value) {
            if ($value === null || $value === '') {
                continue;
            }

            if (! is_string($group) || ! array_key_exists($group, $groups) || ! is_string($value) || ! in_array($value, $groups[$group], true)) {
                throw ValidationException::withMessages(['facets.'.(is_string($group) ? $group : 'unknown') => __('ratings-and-reviews::messages.facet_invalid')]);
            }

            $facets[$group] = $value;
        }

        ksort($facets);

        return $facets === [] ? null : $facets;
    }

    /**
     * The groups with translated labels, for forms and filters.
     *
     * @return list<array{group: string, label: string, values: list<array{value: string, label: string}>}>
     */
    public function options(): array
    {
        $options = [];

        foreach ($this->groups() as $group => $values) {
            $options[] = [
                'group' => $group,
                'label' => $this->label($group),
                'values' => array_map(fn (string $value): array => ['value' => $value, 'label' => $this->label($group, $value)], $values),
            ];
        }

        return $options;
    }

    public function label(string $group, ?string $value = null): string
    {
        $key = 'ratings-and-reviews::enums.facet.'.$group.'.'.($value ?? 'label');
        $line = (string) __($key);

        return $line === $key ? ($value ?? $group) : $line;
    }
}

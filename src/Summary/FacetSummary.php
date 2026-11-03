<?php

namespace RatingsAndReviews\Summary;

use Illuminate\Database\Eloquent\Model;
use RatingsAndReviews\Contracts\Reviewable;
use RatingsAndReviews\Contracts\SummaryExtension;
use RatingsAndReviews\Models\RatingAggregate;
use RatingsAndReviews\Services\ReviewFacets;
use RatingsAndReviews\Support\SectionSettings;

/**
 * Summary key `facets`: per configured facet group, how many counted reviews chose each value.
 */
class FacetSummary implements SummaryExtension
{
    public function __construct(private readonly ReviewFacets $facets) {}

    public function key(): string
    {
        return 'facets';
    }

    /**
     * @return list<array{group: string, label: string, values: list<array{value: string, label: string, count: int}>}>
     */
    public function compute(Model&Reviewable $subject, ?RatingAggregate $aggregate, SectionSettings $settings): array
    {
        $stored = (array) ($aggregate?->facet_counts ?? []);

        return array_map(fn (array $group): array => [
            'group' => $group['group'],
            'label' => $group['label'],
            'values' => array_map(fn (array $value): array => $value + ['count' => (int) ($stored[$group['group']][$value['value']] ?? 0)], $group['values']),
        ], $this->facets->options());
    }
}

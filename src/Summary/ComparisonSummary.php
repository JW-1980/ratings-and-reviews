<?php

namespace RatingsAndReviews\Summary;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use RatingsAndReviews\Contracts\Reviewable;
use RatingsAndReviews\Contracts\SummaryExtension;
use RatingsAndReviews\Models\RatingAggregate;
use RatingsAndReviews\Support\SectionSettings;

/**
 * Summary key `comparisons` ("compared with…"): the alternatives reviewers named most, with
 * how many found this subject better, worse or the same, and `often_switch_to`: the
 * alternative most reviewers found better than this subject ("reviewers often switch to
 * X"), shown from `reviews.switch_min_reviews` such reviews and only when more found it
 * better than worse. Alternatives that no longer exist or are not public are left out.
 */
class ComparisonSummary implements SummaryExtension
{
    public function key(): string
    {
        return 'comparisons';
    }

    /**
     * @return array{alternatives: list<array<string, mixed>>, often_switch_to: array<string, mixed>|null}
     */
    public function compute(Model&Reviewable $subject, ?RatingAggregate $aggregate, SectionSettings $settings): array
    {
        $stored = array_values(array_filter((array) ($aggregate?->comparisons ?? []), 'is_array'));

        if ($stored === []) {
            return ['alternatives' => [], 'often_switch_to' => null];
        }

        $subjects = $this->publicSubjects($stored);
        $alternatives = [];

        foreach ($stored as $row) {
            $model = $subjects[$row['type'].':'.$row['id']] ?? null;

            if ($model !== null) {
                $alternatives[] = [
                    'type' => $row['type'],
                    'id' => (int) $row['id'],
                    'title' => $model->reviewSubjectTitle(),
                    'url' => $model->reviewSubjectUrl(),
                    'better' => (int) $row['better'],
                    'worse' => (int) $row['worse'],
                    'same' => (int) $row['same'],
                    'total' => (int) $row['total'],
                ];
            }
        }

        $minimum = max(1, (int) config('ratings-and-reviews.reviews.switch_min_reviews', 2));
        $switch = null;

        foreach ($alternatives as $alternative) {
            if ($alternative['worse'] >= $minimum && $alternative['worse'] > $alternative['better']
                && ($switch === null || $alternative['worse'] > $switch['worse'])) {
                $switch = $alternative;
            }
        }

        return [
            'alternatives' => array_slice($alternatives, 0, max(0, (int) config('ratings-and-reviews.reviews.comparisons_listed', 5))),
            'often_switch_to' => $switch === null ? null : [
                'type' => $switch['type'],
                'id' => $switch['id'],
                'title' => $switch['title'],
                'url' => $switch['url'],
                'count' => $switch['worse'],
            ],
        ];
    }

    /**
     * The stored alternatives that still exist and are public, one query per type.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return array<string, Model&Reviewable>
     */
    private function publicSubjects(array $rows): array
    {
        $byType = [];

        foreach ($rows as $row) {
            $byType[(string) $row['type']][] = (int) $row['id'];
        }

        $found = [];

        foreach ($byType as $type => $ids) {
            $class = Relation::getMorphedModel($type);

            if ($class === null || ! is_subclass_of($class, Model::class) || ! is_subclass_of($class, Reviewable::class)) {
                continue;
            }

            foreach ($class::query()->whereKey(array_values(array_unique($ids)))->get() as $model) {
                if ($model instanceof Reviewable && $model->reviewsVisibleTo(null)) {
                    $found[$type.':'.$model->getKey()] = $model;
                }
            }
        }

        return $found;
    }
}

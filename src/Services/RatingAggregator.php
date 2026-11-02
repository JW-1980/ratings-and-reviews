<?php

namespace RatingsAndReviews\Services;

use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use RatingsAndReviews\Contracts\Reviewable;
use RatingsAndReviews\Contracts\SummaryExtension;
use RatingsAndReviews\Events\RatingAggregatesRefreshed;
use RatingsAndReviews\Models\RatingAggregate;
use RatingsAndReviews\Models\Review;
use RatingsAndReviews\Models\ReviewTopicRating;
use RatingsAndReviews\Models\TopicRating;
use RatingsAndReviews\Support\ContentStatus;
use RatingsAndReviews\Support\SqlDialect;

/**
 * Rating aggregates of a subject, computed from its approved, counted reviews.
 *
 * Normalisation: every rating is stored with its own scale, and mapped linearly onto
 * 0 … 1 with (value − 1) / (scale_max − 1), so 1 is always the lowest and scale_max the
 * highest score. Shown on a scale of N it becomes 1 + x · (N − 1): 4/5 is 7.75/10, 5/5 is
 * 10/10. Old reviews therefore stay correct when an admin switches the scale.
 *
 * Bayesian average: (v / (v + m)) · R + (m / (v + m)) · C, where R and v are the subject's
 * normalised mean and review count, C the mean over the whole scope and m the median
 * review count of the scope's reviewed subjects. The priors are cached per scope.
 *
 * The stored row also keeps the sum of squares, so an uncertainty band can be derived
 * without another query; extra summary keys come from tagged SummaryExtension services.
 */
class RatingAggregator
{
    public const EXTENSION_TAG = 'ratings-and-reviews.summary-extensions';

    public function __construct(
        private readonly Cache $cache,
        private readonly SectionSettingsRepository $settings,
        private readonly RatingTopicCatalog $topics,
        private readonly Container $container,
        private readonly Dispatcher $events,
        private readonly ReviewFacets $facets,
    ) {}

    public function refresh(Model&Reviewable $subject): RatingAggregate
    {
        $reviews = $this->countedReviews($subject);

        $rows = (clone $reviews)
            ->toBase()
            ->selectRaw('scale_max, rating, COUNT(*) as total, MAX(created_at) as latest')
            ->groupBy('scale_max', 'rating')
            ->get();

        $count = 0;
        $sum = 0.0;
        $squares = 0.0;
        $histogram = [];
        $latest = null;

        foreach ($rows as $row) {
            $total = (int) $row->total;
            $normalized = Review::normalize((int) $row->rating, (int) $row->scale_max);
            $count += $total;
            $sum += $normalized * $total;
            $squares += $normalized * $normalized * $total;
            $histogram[(string) $row->scale_max][(string) $row->rating] = $total;
            $latest = $latest === null || $row->latest > $latest ? $row->latest : $latest;
        }

        $topics = [];

        $topicRows = ReviewTopicRating::query()
            ->whereIn('review_id', (clone $reviews)->select('id'))
            ->toBase()
            ->selectRaw('topic_id, scale_max, value, COUNT(*) as total')
            ->groupBy('topic_id', 'scale_max', 'value')
            ->get();

        foreach ([...$topicRows, ...$this->standaloneTopicRows($subject, $reviews)] as $row) {
            $key = (string) $row->topic_id;
            $total = (int) $row->total;
            $normalized = Review::normalize((int) $row->value, (int) $row->scale_max);
            $topics[$key] ??= ['count' => 0, 'sum' => 0.0, 'sum_squares' => 0.0];
            $topics[$key]['count'] += $total;
            $topics[$key]['sum'] += $normalized * $total;
            $topics[$key]['sum_squares'] += $normalized * $normalized * $total;
        }

        $aggregate = RatingAggregate::query()->updateOrCreate(
            ['reviewable_type' => $subject->getMorphClass(), 'reviewable_id' => $subject->getKey()],
            [
                'scope' => $subject->ratingScope(),
                'review_count' => $count,
                'normalized_sum' => $sum,
                'normalized_sum_squares' => $squares,
                'histogram' => $histogram,
                'topics' => $topics,
                'facet_counts' => $this->facetCounts($reviews),
                'monthly' => $this->monthly($reviews),
                'comparisons' => $this->comparisons($reviews),
                'last_review_at' => $latest,
                'computed_at' => now(),
            ],
        );

        $this->events->dispatch(new RatingAggregatesRefreshed($subject, $aggregate));

        return $aggregate;
    }

    /**
     * The summary shown on the subject, on the scale of its section.
     *
     * @return array<string, mixed>
     */
    public function summary(Model&Reviewable $subject): array
    {
        $settings = $this->settings->for($subject->reviewSectionKey());
        $aggregate = RatingAggregate::query()
            ->where('reviewable_type', $subject->getMorphClass())
            ->where('reviewable_id', $subject->getKey())
            ->first() ?? $this->refresh($subject);

        $scale = $settings->reviewScale;
        $count = $aggregate->review_count;
        $mean = $count > 0 ? $aggregate->normalized_sum / $count : null;
        $priors = $this->priors($subject->ratingScope());

        $summary = [
            'scale_max' => $scale,
            'count' => $count,
            'average' => $mean === null ? null : $this->display($mean, $scale),
            // No own reviews: no score at all, rather than showing the scope's prior as if it were one.
            'bayesian_average' => $mean === null ? null : $this->display($this->bayesian($mean, $count, $priors['mean'], $priors['weight']), $scale),
            'histogram' => $this->histogram((array) $aggregate->histogram, $scale),
            'topics' => $this->topicSummaries($subject, (array) $aggregate->topics),
            'computed_at' => $aggregate->computed_at?->toIso8601String(),
        ];

        foreach ($this->extensions() as $extension) {
            $summary[$extension->key()] = $extension->compute($subject, $aggregate, $settings);
        }

        return $summary;
    }

    /** (v / (v + m)) · R + (m / (v + m)) · C, on the normalised 0 … 1 scale. */
    public function bayesian(float $mean, int $count, float $priorMean, float $priorWeight): float
    {
        if ($count <= 0) {
            return $priorMean;
        }

        if ($priorWeight <= 0.0) {
            return $mean;
        }

        return ($count / ($count + $priorWeight)) * $mean + ($priorWeight / ($count + $priorWeight)) * $priorMean;
    }

    /**
     * The scope's mean (normalised) and the median review count of its reviewed subjects,
     * cached for `reviews.priors_cache_seconds`.
     *
     * @return array{mean: float, weight: float, count: int}
     */
    public function priors(string $scope): array
    {
        return $this->cache->remember('rr:rating-priors:'.$scope, max(1, (int) config('ratings-and-reviews.reviews.priors_cache_seconds', 21600)), function () use ($scope): array {
            $rows = RatingAggregate::query()->where('scope', $scope)->where('review_count', '>', 0);
            $counts = (clone $rows)->orderBy('review_count')->pluck('review_count')->map(fn (mixed $value): int => (int) $value)->values()->all();
            $totals = (clone $rows)->toBase()->selectRaw('SUM(normalized_sum) as total_sum, SUM(review_count) as total_count')->first();
            $reviewCount = (int) ($totals->total_count ?? 0);

            return [
                'mean' => $reviewCount > 0 ? (float) $totals->total_sum / $reviewCount : 0.0,
                'weight' => $this->median($counts),
                'count' => $reviewCount,
            ];
        });
    }

    public function forgetPriors(string $scope): void
    {
        $this->cache->forget('rr:rating-priors:'.$scope);
    }

    /** A normalised 0 … 1 value on a 1 … $scale display scale. */
    public function display(float $normalized, int $scale): float
    {
        return round(1 + max(0.0, min(1.0, $normalized)) * ($scale - 1), 2);
    }

    /**
     * Counted reviews per facet group and value: [group => [value => count]].
     *
     * @param  Builder<Review>  $reviews
     * @return array<string, array<string, int>>
     */
    private function facetCounts(Builder $reviews): array
    {
        $counts = [];

        foreach (array_keys($this->facets->groups()) as $group) {
            // $group matches ^[a-z][a-z0-9_]*$ (ReviewFacets), so the JSON path is safe; it is bound anyway.
            $rows = (clone $reviews)->toBase()
                ->whereNotNull('facets')
                ->selectRaw(SqlDialect::jsonText('facets').' as facet_value, COUNT(*) as total', [SqlDialect::jsonTextBinding($group)])
                ->groupBy('facet_value')
                ->get();

            foreach ($rows as $row) {
                if (is_string($row->facet_value) && $row->facet_value !== '' && $row->facet_value !== 'null') {
                    $counts[$group][$row->facet_value] = (int) $row->total;
                }
            }
        }

        return $counts;
    }

    /**
     * Counted reviews per calendar month of writing: [YYYY-MM => [count, sum (normalised)]].
     *
     * @param  Builder<Review>  $reviews
     * @return array<string, array{count: int, sum: float}>
     */
    private function monthly(Builder $reviews): array
    {
        $months = [];

        $rows = (clone $reviews)->toBase()
            ->selectRaw(SqlDialect::yearMonth('created_at').' as month, scale_max, rating, COUNT(*) as total')
            ->groupBy('month', 'scale_max', 'rating')
            ->get();

        foreach ($rows as $row) {
            $month = (string) $row->month;
            $months[$month] ??= ['count' => 0, 'sum' => 0.0];
            $months[$month]['count'] += (int) $row->total;
            $months[$month]['sum'] += Review::normalize((int) $row->rating, (int) $row->scale_max) * (int) $row->total;
        }

        ksort($months);

        return $months;
    }

    /**
     * "Compared with…" per alternative: how many counted reviews found the subject better,
     * worse or the same, most compared first (at most 50).
     *
     * @param  Builder<Review>  $reviews
     * @return list<array{type: string, id: int, better: int, worse: int, same: int, total: int}>
     */
    private function comparisons(Builder $reviews): array
    {
        $alternatives = [];

        $rows = (clone $reviews)->toBase()
            ->whereNotNull('compared_type')
            ->whereNotNull('compared_id')
            ->whereNotNull('comparison')
            ->selectRaw('compared_type, compared_id, comparison, COUNT(*) as total')
            ->groupBy('compared_type', 'compared_id', 'comparison')
            ->get();

        foreach ($rows as $row) {
            $key = $row->compared_type.':'.$row->compared_id;
            $alternatives[$key] ??= ['type' => (string) $row->compared_type, 'id' => (int) $row->compared_id, 'better' => 0, 'worse' => 0, 'same' => 0, 'total' => 0];

            if (in_array($row->comparison, ['better', 'worse', 'same'], true)) {
                $alternatives[$key][$row->comparison] += (int) $row->total;
                $alternatives[$key]['total'] += (int) $row->total;
            }
        }

        $list = array_values($alternatives);
        usort($list, fn (array $a, array $b): int => [$b['total'], $b['worse'], $a['type'], $a['id']] <=> [$a['total'], $a['worse'], $b['type'], $b['id']]);

        return array_slice($list, 0, 50);
    }

    /**
     * Counted topic ratings given without a review, per topic, scale and value; a member's
     * score is left out when they rated the same topic in one of the counted reviews.
     *
     * @param  Builder<Review>  $reviews
     * @return list<object{topic_id: int, scale_max: int, value: int, total: int}>
     */
    private function standaloneTopicRows(Model $subject, Builder $reviews): array
    {
        $ratings = (new TopicRating)->getTable();
        $reviewRatings = (new ReviewTopicRating)->getTable();
        $reviewTable = (new Review)->getTable();

        return TopicRating::query()
            ->forSubject($subject)
            ->where('counted', true)
            ->whereNotExists(fn ($exists) => $exists->selectRaw('1')
                ->from($reviewRatings)
                ->join($reviewTable, $reviewTable.'.id', '=', $reviewRatings.'.review_id')
                ->whereIn($reviewTable.'.id', (clone $reviews)->select('id'))
                ->whereColumn($reviewTable.'.user_id', $ratings.'.user_id')
                ->whereColumn($reviewRatings.'.topic_id', $ratings.'.topic_id'))
            ->toBase()
            ->selectRaw('topic_id, scale_max, value, COUNT(*) as total')
            ->groupBy('topic_id', 'scale_max', 'value')
            ->get()
            ->all();
    }

    /**
     * @return Builder<Review>
     */
    private function countedReviews(Model $subject): Builder
    {
        return Review::query()
            ->forSubject($subject)
            ->where('status', ContentStatus::Approved->value)
            ->where('counted', true);
    }

    /**
     * Every stored rating mapped onto the display scale and rounded to a whole bucket.
     *
     * @param  array<string, array<string, int>>  $stored
     * @return array<int, int> bucket (1 … scale) => count, highest first
     */
    private function histogram(array $stored, int $scale): array
    {
        $buckets = array_fill_keys(range($scale, 1), 0);

        foreach ($stored as $scaleMax => $ratings) {
            foreach ((array) $ratings as $rating => $count) {
                $bucket = (int) round($this->display(Review::normalize((int) $rating, (int) $scaleMax), $scale), 0, PHP_ROUND_HALF_UP);
                $buckets[max(1, min($scale, $bucket))] += (int) $count;
            }
        }

        return $buckets;
    }

    /**
     * @param  array<string, array{count: int, sum: float, sum_squares: float}>  $stored
     * @return list<array{id: int, name: string, scale_max: int, count: int, average: float|null}>
     */
    private function topicSummaries(Model&Reviewable $subject, array $stored): array
    {
        return $this->topics->effectiveFor($subject)->map(function ($topic) use ($stored): array {
            $totals = $stored[(string) $topic->id] ?? ['count' => 0, 'sum' => 0.0];
            $count = (int) $totals['count'];

            return [
                'id' => $topic->id,
                'name' => $topic->localizedName(),
                'scale_max' => $topic->scale_max,
                'count' => $count,
                'average' => $count > 0 ? $this->display((float) $totals['sum'] / $count, $topic->scale_max) : null,
            ];
        })->values()->all();
    }

    /**
     * @return list<SummaryExtension>
     */
    private function extensions(): array
    {
        $extensions = [];

        foreach ($this->container->tagged(self::EXTENSION_TAG) as $extension) {
            if ($extension instanceof SummaryExtension) {
                $extensions[] = $extension;
            }
        }

        return $extensions;
    }

    /**
     * @param  list<int>  $sorted
     */
    private function median(array $sorted): float
    {
        $n = count($sorted);

        if ($n === 0) {
            return 0.0;
        }

        $middle = intdiv($n, 2);

        return $n % 2 === 1 ? (float) $sorted[$middle] : ($sorted[$middle - 1] + $sorted[$middle]) / 2.0;
    }
}

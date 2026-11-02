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
use RatingsAndReviews\Support\ContentStatus;

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

        foreach ($topicRows as $row) {
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
        $settings = $this->settings->for($subject->reviewSection());
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

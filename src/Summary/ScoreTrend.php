<?php

namespace RatingsAndReviews\Summary;

use Illuminate\Database\Eloquent\Model;
use RatingsAndReviews\Contracts\Reviewable;
use RatingsAndReviews\Contracts\SummaryExtension;
use RatingsAndReviews\Models\RatingAggregate;
use RatingsAndReviews\Support\SectionSettings;

/**
 * Summary key `trend`: the average per calendar month (month of writing) over the last
 * `reviews.trend_months` months up to the current one, on the display scale, for a
 * sparkline. Months without reviews have count 0 and average null. Empty when the subject
 * has no counted reviews.
 */
class ScoreTrend implements SummaryExtension
{
    public function key(): string
    {
        return 'trend';
    }

    /**
     * @return array{months: int, points: list<array{month: string, count: int, average: float|null}>}
     */
    public function compute(Model&Reviewable $subject, ?RatingAggregate $aggregate, SectionSettings $settings): array
    {
        $months = max(1, min(120, (int) config('ratings-and-reviews.reviews.trend_months', 12)));
        $stored = (array) ($aggregate?->monthly ?? []);

        if ($stored === [] || ($aggregate?->review_count ?? 0) === 0) {
            return ['months' => $months, 'points' => []];
        }

        $span = $settings->reviewScale - 1;
        $points = [];
        $cursor = now()->startOfMonth()->subMonthsNoOverflow($months - 1);

        for ($i = 0; $i < $months; $i++) {
            $key = $cursor->format('Y-m');
            $count = (int) ($stored[$key]['count'] ?? 0);
            $points[] = [
                'month' => $key,
                'count' => $count,
                'average' => $count > 0 ? round(1 + max(0.0, min(1.0, (float) $stored[$key]['sum'] / $count)) * $span, 2) : null,
            ];
            $cursor = $cursor->addMonthNoOverflow();
        }

        return ['months' => $months, 'points' => $points];
    }
}

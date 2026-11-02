<?php

namespace RatingsAndReviews\Summary;

use Illuminate\Database\Eloquent\Model;
use RatingsAndReviews\Contracts\Reviewable;
use RatingsAndReviews\Contracts\SummaryExtension;
use RatingsAndReviews\Models\RatingAggregate;
use RatingsAndReviews\Support\SectionSettings;

/**
 * Summary key `uncertainty`: the 95% confidence interval of the (plain) average, from the
 * standard error of the stored normalised sum and sum of squares, reported on the display
 * scale ("8.1 ± 0.4").
 *
 *   mean = Σx / n,  s² = (Σx² − n·mean²) / (n − 1),  SE = s / √n,  margin = t · SE
 *
 * t is Student's 0.975 quantile for n − 1 degrees of freedom (1.96 from 121 on), so small
 * samples get the wider band they need. The interval is clamped to the scale. Below two
 * reviews there is no spread to measure and the margin is null; below `few_reviews_below`
 * counted reviews `few_reviews` is true so the UI can say "few reviews yet".
 */
class UncertaintyBand implements SummaryExtension
{
    /** Student's t, two-sided 95%, for 1 … 30 degrees of freedom. */
    private const T = [
        1 => 12.706, 2 => 4.303, 3 => 3.182, 4 => 2.776, 5 => 2.571, 6 => 2.447, 7 => 2.365, 8 => 2.306, 9 => 2.262,
        10 => 2.228, 11 => 2.201, 12 => 2.179, 13 => 2.160, 14 => 2.145, 15 => 2.131, 16 => 2.120, 17 => 2.110,
        18 => 2.101, 19 => 2.093, 20 => 2.086, 21 => 2.080, 22 => 2.074, 23 => 2.069, 24 => 2.064, 25 => 2.060,
        26 => 2.056, 27 => 2.052, 28 => 2.048, 29 => 2.045, 30 => 2.042,
    ];

    public function key(): string
    {
        return 'uncertainty';
    }

    /**
     * @return array{confidence: float, margin: float|null, lower: float|null, upper: float|null, standard_error: float|null, few_reviews: bool, few_reviews_below: int}
     */
    public function compute(Model&Reviewable $subject, ?RatingAggregate $aggregate, SectionSettings $settings): array
    {
        $below = max(0, (int) config('ratings-and-reviews.reviews.few_reviews_below', 5));
        $count = $aggregate->review_count ?? 0;
        $band = ['confidence' => 0.95, 'margin' => null, 'lower' => null, 'upper' => null, 'standard_error' => null, 'few_reviews' => $count < $below, 'few_reviews_below' => $below];

        if ($aggregate === null || $count < 2) {
            return $band;
        }

        $span = $settings->reviewScale - 1;
        $mean = $aggregate->normalized_sum / $count;
        $variance = max(0.0, ($aggregate->normalized_sum_squares - $count * $mean * $mean) / ($count - 1));
        $standardError = sqrt($variance / $count);
        $margin = self::tCritical($count - 1) * $standardError;

        return [
            'margin' => round($margin * $span, 2),
            'lower' => round(1 + max(0.0, $mean - $margin) * $span, 2),
            'upper' => round(1 + min(1.0, $mean + $margin) * $span, 2),
            'standard_error' => round($standardError * $span, 2),
        ] + $band;
    }

    /** Conservative: between table values the next smaller df (the larger t) is used. */
    public static function tCritical(int $degreesOfFreedom): float
    {
        return match (true) {
            $degreesOfFreedom < 1 => INF,
            $degreesOfFreedom <= 30 => self::T[$degreesOfFreedom],
            $degreesOfFreedom <= 40 => 2.042,
            $degreesOfFreedom <= 60 => 2.021,
            $degreesOfFreedom <= 120 => 2.000,
            default => 1.960,
        };
    }
}

<?php

namespace RatingsAndReviews\Summary;

use Illuminate\Database\Eloquent\Model;
use RatingsAndReviews\Contracts\Reviewable;
use RatingsAndReviews\Contracts\SummaryExtension;
use RatingsAndReviews\Models\RatingAggregate;
use RatingsAndReviews\Services\RatingAggregator;
use RatingsAndReviews\Support\SectionSettings;

/**
 * Summary key `follow_ups`: how ratings change after a while, over the counted reviews with a
 * follow-up, on the section's scale: the average before (the review's rating when the
 * follow-up was written), after and the change. The averages are null below
 * `reviews.follow_ups.min_for_change` follow-ups; `enabled` says whether the section takes them.
 */
class FollowUpSummary implements SummaryExtension
{
    public function key(): string
    {
        return 'follow_ups';
    }

    /**
     * @return array{enabled: bool, count: int, min_for_change: int, average_before: float|null, average_after: float|null, average_change: float|null}
     */
    public function compute(Model&Reviewable $subject, ?RatingAggregate $aggregate, SectionSettings $settings): array
    {
        $stats = (array) ($aggregate?->follow_up_stats ?? []);
        $count = (int) ($stats['count'] ?? 0);
        $minimum = max(1, (int) config('ratings-and-reviews.reviews.follow_ups.min_for_change', 3));
        $scale = $settings->reviewScale;
        $aggregator = app(RatingAggregator::class);

        if ($count < $minimum) {
            return ['enabled' => $settings->reviewsEnabled && $settings->reviewFollowUpAfterDays > 0, 'count' => $count, 'min_for_change' => $minimum, 'average_before' => null, 'average_after' => null, 'average_change' => null];
        }

        $before = round($aggregator->display((float) $stats['before_sum'] / $count, $scale), 1);
        $after = round($aggregator->display((float) $stats['after_sum'] / $count, $scale), 1);

        return [
            'enabled' => $settings->reviewsEnabled && $settings->reviewFollowUpAfterDays > 0,
            'count' => $count,
            'min_for_change' => $minimum,
            'average_before' => $before,
            'average_after' => $after,
            'average_change' => round($after - $before, 1),
        ];
    }
}

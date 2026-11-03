<?php

namespace RatingsAndReviews\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;

/**
 * Stored rating aggregates of one subject, written by RefreshRatingAggregates. Scores are
 * kept normalised (0 = lowest, 1 = highest) so reviews on different scales add up.
 *
 * @property int $id
 * @property string $reviewable_type
 * @property int $reviewable_id
 * @property string $scope
 * @property int $review_count
 * @property float $normalized_sum
 * @property float $normalized_sum_squares
 * @property array<string, array<string, int>>|null $histogram [scale_max => [rating => count]]
 * @property array<string, array{count: int, sum: float, sum_squares: float}>|null $topics [topic id => totals]
 * @property array<string, array<string, int>>|null $facet_counts [group => [value => count]]
 * @property array<string, array{count: int, sum: float}>|null $monthly [YYYY-MM => totals]
 * @property list<array{type: string, id: int, better: int, worse: int, same: int, total: int}>|null $comparisons
 * @property array<string, array{answered: int, values: array<string, int>, options: array<string, int>}>|null $question_counts [question id => totals]
 * @property array{count: int, before_sum: float, after_sum: float}|null $follow_up_stats normalised sums over counted reviews with a follow-up
 * @property Carbon|null $last_review_at
 * @property Carbon|null $computed_at
 */
class RatingAggregate extends Model
{
    protected $table = 'rr_rating_aggregates';

    protected $fillable = [
        'reviewable_type', 'reviewable_id', 'scope', 'review_count', 'normalized_sum', 'normalized_sum_squares',
        'histogram', 'topics', 'facet_counts', 'monthly', 'comparisons', 'question_counts', 'follow_up_stats', 'last_review_at', 'computed_at',
    ];

    protected function casts(): array
    {
        return [
            'reviewable_id' => 'integer',
            'review_count' => 'integer',
            'normalized_sum' => 'float',
            'normalized_sum_squares' => 'float',
            'histogram' => 'array',
            'topics' => 'array',
            'facet_counts' => 'array',
            'monthly' => 'array',
            'comparisons' => 'array',
            'question_counts' => 'array',
            'follow_up_stats' => 'array',
            'last_review_at' => 'datetime',
            'computed_at' => 'datetime',
        ];
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function reviewable(): MorphTo
    {
        return $this->morphTo();
    }
}

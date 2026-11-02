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
 * @property Carbon|null $last_review_at
 * @property Carbon|null $computed_at
 */
class RatingAggregate extends Model
{
    protected $table = 'rr_rating_aggregates';

    protected $fillable = [
        'reviewable_type', 'reviewable_id', 'scope', 'review_count', 'normalized_sum', 'normalized_sum_squares',
        'histogram', 'topics', 'last_review_at', 'computed_at',
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

<?php

namespace RatingsAndReviews\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $review_id
 * @property int $topic_id
 * @property int $value
 * @property int $scale_max
 */
class ReviewTopicRating extends Model
{
    protected $table = 'rr_review_topic_ratings';

    protected $fillable = ['review_id', 'topic_id', 'value', 'scale_max'];

    protected function casts(): array
    {
        return ['review_id' => 'integer', 'topic_id' => 'integer', 'value' => 'integer', 'scale_max' => 'integer'];
    }

    /**
     * @return BelongsTo<Review, $this>
     */
    public function review(): BelongsTo
    {
        return $this->belongsTo(Review::class);
    }

    /**
     * @return BelongsTo<RatingTopic, $this>
     */
    public function topic(): BelongsTo
    {
        return $this->belongsTo(RatingTopic::class, 'topic_id');
    }
}

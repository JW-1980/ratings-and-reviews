<?php

namespace RatingsAndReviews\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * The author's follow-up on their own review ("after a month"): the rating now, the rating
 * of the review at that moment and an optional plain-text note. It belongs to the review:
 * public while the review is, moderated, reported and erased with it.
 *
 * @property int $id
 * @property int $review_id
 * @property int $user_id
 * @property int $rating
 * @property int $scale_max
 * @property int $initial_rating
 * @property int $initial_scale_max
 * @property string|null $body_text
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class ReviewFollowUp extends Model
{
    protected $table = 'rr_review_follow_ups';

    protected $fillable = ['review_id', 'user_id', 'rating', 'scale_max', 'initial_rating', 'initial_scale_max', 'body_text'];

    protected function casts(): array
    {
        return [
            'review_id' => 'integer',
            'user_id' => 'integer',
            'rating' => 'integer',
            'scale_max' => 'integer',
            'initial_rating' => 'integer',
            'initial_scale_max' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Review, $this>
     */
    public function review(): BelongsTo
    {
        return $this->belongsTo(Review::class);
    }
}

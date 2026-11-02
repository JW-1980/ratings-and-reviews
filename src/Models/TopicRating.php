<?php

namespace RatingsAndReviews\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;

/**
 * A member's score for one rating topic of a subject, given without a review (see
 * Services\TopicRatings). Review-attached scores are ReviewTopicRating.
 *
 * @property int $id
 * @property string $reviewable_type
 * @property int $reviewable_id
 * @property int $user_id
 * @property int $topic_id
 * @property int $value
 * @property int $scale_max
 * @property bool $counted
 * @property string|null $uncounted_reason
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class TopicRating extends Model
{
    protected $table = 'rr_topic_ratings';

    protected $fillable = [
        'reviewable_type', 'reviewable_id', 'user_id', 'topic_id', 'value', 'scale_max', 'counted', 'uncounted_reason',
        'ip_hash', 'network_hash', 'device_hash', 'created_at', 'updated_at',
    ];

    protected $hidden = ['ip_hash', 'network_hash', 'device_hash'];

    protected function casts(): array
    {
        return [
            'reviewable_id' => 'integer',
            'user_id' => 'integer',
            'topic_id' => 'integer',
            'value' => 'integer',
            'scale_max' => 'integer',
            'counted' => 'boolean',
        ];
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function reviewable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * @return BelongsTo<RatingTopic, $this>
     */
    public function topic(): BelongsTo
    {
        return $this->belongsTo(RatingTopic::class, 'topic_id');
    }

    /**
     * @param  Builder<TopicRating>  $query
     */
    public function scopeForSubject(Builder $query, Model $subject): void
    {
        $query->where('reviewable_type', $subject->getMorphClass())->where('reviewable_id', $subject->getKey());
    }
}

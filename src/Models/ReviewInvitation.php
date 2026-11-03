<?php

namespace RatingsAndReviews\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $user_id
 * @property string $reviewable_type
 * @property int $reviewable_id
 * @property string $source
 * @property Carbon $due_at
 * @property Carbon|null $sent_at
 * @property Carbon|null $completed_at
 * @property Carbon|null $dismissed_at
 */
class ReviewInvitation extends Model
{
    protected $table = 'rr_review_invitations';

    protected $fillable = ['user_id', 'reviewable_type', 'reviewable_id', 'source', 'due_at', 'sent_at', 'completed_at', 'dismissed_at'];

    protected function casts(): array
    {
        return [
            'user_id' => 'integer',
            'reviewable_id' => 'integer',
            'due_at' => 'datetime',
            'sent_at' => 'datetime',
            'completed_at' => 'datetime',
            'dismissed_at' => 'datetime',
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

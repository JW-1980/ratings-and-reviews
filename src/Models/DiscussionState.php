<?php

namespace RatingsAndReviews\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;

/**
 * Per-subject discussion state: slow mode and locking (used by those features).
 *
 * @property int $id
 * @property string $commentable_type
 * @property int $commentable_id
 * @property int|null $slow_mode_seconds
 * @property Carbon|null $locked_at
 * @property int|null $locked_by
 */
class DiscussionState extends Model
{
    protected $table = 'rr_discussion_states';

    protected $fillable = ['commentable_type', 'commentable_id', 'slow_mode_seconds', 'locked_at', 'locked_by'];

    protected function casts(): array
    {
        return ['commentable_id' => 'integer', 'slow_mode_seconds' => 'integer', 'locked_at' => 'datetime', 'locked_by' => 'integer'];
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function commentable(): MorphTo
    {
        return $this->morphTo();
    }
}

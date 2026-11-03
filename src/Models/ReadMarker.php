<?php

namespace RatingsAndReviews\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;

/**
 * When a user last read a discussion ("new since your last visit").
 *
 * @property int $id
 * @property int $user_id
 * @property string $commentable_type
 * @property int $commentable_id
 * @property Carbon $last_read_at
 */
class ReadMarker extends Model
{
    protected $table = 'rr_read_markers';

    protected $fillable = ['user_id', 'commentable_type', 'commentable_id', 'last_read_at'];

    protected function casts(): array
    {
        return ['user_id' => 'integer', 'commentable_id' => 'integer', 'last_read_at' => 'datetime'];
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function commentable(): MorphTo
    {
        return $this->morphTo();
    }
}

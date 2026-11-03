<?php

namespace RatingsAndReviews\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $poll_id
 * @property string $label
 * @property int $sort_order
 * @property int $votes_count
 */
class PollOption extends Model
{
    protected $table = 'rr_poll_options';

    protected $fillable = ['poll_id', 'label', 'sort_order', 'votes_count'];

    protected function casts(): array
    {
        return ['poll_id' => 'integer', 'sort_order' => 'integer', 'votes_count' => 'integer'];
    }

    /**
     * @return BelongsTo<Poll, $this>
     */
    public function poll(): BelongsTo
    {
        return $this->belongsTo(Poll::class);
    }
}

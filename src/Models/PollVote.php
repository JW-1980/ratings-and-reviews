<?php

namespace RatingsAndReviews\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One vote in a poll, with the same identity and counting fields as rr_votes.
 *
 * @property int $id
 * @property int $poll_id
 * @property int $option_id
 * @property int|null $user_id
 * @property string $voter_key
 * @property string|null $network_hash
 * @property string|null $device_hash
 * @property bool $counted
 * @property string|null $uncounted_reason
 */
class PollVote extends Model
{
    protected $table = 'rr_poll_votes';

    protected $fillable = ['poll_id', 'option_id', 'user_id', 'voter_key', 'network_hash', 'device_hash', 'counted', 'uncounted_reason'];

    protected $hidden = ['voter_key', 'network_hash', 'device_hash'];

    protected function casts(): array
    {
        return ['poll_id' => 'integer', 'option_id' => 'integer', 'user_id' => 'integer', 'counted' => 'boolean'];
    }

    /**
     * @return BelongsTo<Poll, $this>
     */
    public function poll(): BelongsTo
    {
        return $this->belongsTo(Poll::class);
    }

    /**
     * @return BelongsTo<PollOption, $this>
     */
    public function option(): BelongsTo
    {
        return $this->belongsTo(PollOption::class, 'option_id');
    }
}

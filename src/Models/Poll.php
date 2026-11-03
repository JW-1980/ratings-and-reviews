<?php

namespace RatingsAndReviews\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A mini poll (2–4 options) attached to a comment.
 *
 * @property int $id
 * @property int $comment_id
 * @property string $question
 * @property Carbon|null $closes_at
 */
class Poll extends Model
{
    protected $table = 'rr_polls';

    protected $fillable = ['comment_id', 'question', 'closes_at'];

    protected function casts(): array
    {
        return ['comment_id' => 'integer', 'closes_at' => 'datetime'];
    }

    /**
     * @return BelongsTo<Comment, $this>
     */
    public function comment(): BelongsTo
    {
        return $this->belongsTo(Comment::class);
    }

    /**
     * @return HasMany<PollOption, $this>
     */
    public function options(): HasMany
    {
        return $this->hasMany(PollOption::class)->orderBy('sort_order');
    }

    /**
     * @return HasMany<PollVote, $this>
     */
    public function votes(): HasMany
    {
        return $this->hasMany(PollVote::class);
    }
}

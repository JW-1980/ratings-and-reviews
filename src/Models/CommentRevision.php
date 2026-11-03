<?php

namespace RatingsAndReviews\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * The text a comment had before an edit.
 *
 * @property int $id
 * @property int $comment_id
 * @property int $revision
 * @property string $body_html
 * @property string $body_text
 * @property int|null $edited_by
 * @property Carbon $edited_at
 */
class CommentRevision extends Model
{
    public $timestamps = false;

    protected $table = 'rr_comment_revisions';

    protected $fillable = ['comment_id', 'revision', 'body_html', 'body_text', 'edited_by', 'edited_at'];

    protected function casts(): array
    {
        return ['comment_id' => 'integer', 'revision' => 'integer', 'edited_by' => 'integer', 'edited_at' => 'datetime'];
    }

    /**
     * @return BelongsTo<Comment, $this>
     */
    public function comment(): BelongsTo
    {
        return $this->belongsTo(Comment::class);
    }
}

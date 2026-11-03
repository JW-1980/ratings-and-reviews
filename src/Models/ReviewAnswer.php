<?php

namespace RatingsAndReviews\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One answer row: a scale answer (`option_key` '' and `value`) or one chosen option of a
 * choice or multi question.
 *
 * @property int $id
 * @property int $review_id
 * @property int $question_id
 * @property string $option_key
 * @property int|null $value
 */
class ReviewAnswer extends Model
{
    protected $table = 'rr_review_answers';

    protected $fillable = ['review_id', 'question_id', 'option_key', 'value'];

    protected function casts(): array
    {
        return ['review_id' => 'integer', 'question_id' => 'integer', 'value' => 'integer'];
    }

    /**
     * @return BelongsTo<ReviewQuestion, $this>
     */
    public function question(): BelongsTo
    {
        return $this->belongsTo(ReviewQuestion::class, 'question_id');
    }
}

<?php

namespace RatingsAndReviews\Concerns;

use Illuminate\Database\Eloquent\Relations\MorphMany;
use RatingsAndReviews\Models\Comment;
use RatingsAndReviews\Support\ContentStatus;

/**
 * Relations for a host model implementing Commentable.
 */
trait HasComments
{
    /**
     * @return MorphMany<Comment, $this>
     */
    public function comments(): MorphMany
    {
        return $this->morphMany(Comment::class, 'commentable');
    }

    /**
     * @return MorphMany<Comment, $this>
     */
    public function approvedComments(): MorphMany
    {
        return $this->comments()->where('status', ContentStatus::Approved->value);
    }
}

<?php

namespace RatingsAndReviews\Concerns;

use Illuminate\Database\Eloquent\Relations\MorphMany;
use RatingsAndReviews\Models\Comment;
use RatingsAndReviews\Support\ContentStatus;

/**
 * Relations for a host model implementing Commentable. They are prefixed with `rr` so they
 * never clash with a host's own `comments()` relation; the package itself never calls them
 * (it queries rr_comments by morph type and id).
 */
trait HasComments
{
    /**
     * Every package comment on the model, in any status.
     *
     * @return MorphMany<Comment, $this>
     */
    public function rrComments(): MorphMany
    {
        return $this->morphMany(Comment::class, 'commentable');
    }

    /**
     * The public comments: approved, published (a comment inside its undo window is approved
     * but not yet published) and not withheld for an erasure request.
     *
     * @return MorphMany<Comment, $this>
     */
    public function rrApprovedComments(): MorphMany
    {
        return $this->rrComments()->where('status', ContentStatus::Approved->value)->whereNotNull('published_at')->whereNull('erasure_withheld_at');
    }
}

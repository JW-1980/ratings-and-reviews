<?php

namespace RatingsAndReviews\Contracts;

use Illuminate\Contracts\Auth\Authenticatable;

/**
 * A host model that carries a threaded discussion. Implement it on an Eloquent model
 * registered in the host's morph map; use the HasComments trait for the relations.
 */
interface Commentable
{
    /** The section whose settings apply (depth, editor tools, vote dimensions, …). */
    public function commentSection(): string;

    /** Whether new comments and edits by authors are accepted right now. */
    public function acceptsComments(): bool;

    /** Whether this viewer (null: a guest) may see the subject and its discussion. */
    public function commentsVisibleTo(?Authenticatable $viewer): bool;

    public function discussionTitle(): string;

    public function discussionUrl(): ?string;
}

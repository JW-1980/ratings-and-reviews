<?php

namespace RatingsAndReviews\Contracts;

use Illuminate\Contracts\Auth\Authenticatable;

/**
 * A host model that can be reviewed (product, additive, brand, …). Implement it on an
 * Eloquent model registered in the host's morph map; use the HasReviews trait for the
 * relations.
 */
interface Reviewable
{
    /** The section whose settings apply to reviews of this subject (scale, extended fields, …). */
    public function reviewSectionKey(): string;

    /** Whether new reviews and edits are accepted right now. */
    public function acceptsReviews(): bool;

    /** Whether this viewer (null: a guest) may see the subject and its reviews. */
    public function reviewsVisibleTo(?Authenticatable $viewer): bool;

    public function reviewSubjectTitle(): string;

    public function reviewSubjectUrl(): ?string;

    /** The rating-topic scope, also the population whose median review count is the Bayesian prior. */
    public function ratingScope(): string;

    /**
     * The category chain for rating topics, from the most general to the most specific
     * category key (for example ['food', 'drinks', 'coffee']). Empty: scope topics only.
     *
     * @return list<string>
     */
    public function ratingCategoryPath(): array;
}

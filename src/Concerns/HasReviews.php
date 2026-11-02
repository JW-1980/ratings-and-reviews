<?php

namespace RatingsAndReviews\Concerns;

use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use RatingsAndReviews\Models\RatingAggregate;
use RatingsAndReviews\Models\Review;
use RatingsAndReviews\Support\ContentStatus;

/**
 * Relations for a host model implementing Reviewable.
 */
trait HasReviews
{
    /**
     * @return MorphMany<Review, $this>
     */
    public function reviews(): MorphMany
    {
        return $this->morphMany(Review::class, 'reviewable');
    }

    /**
     * @return MorphMany<Review, $this>
     */
    public function approvedReviews(): MorphMany
    {
        return $this->reviews()->where('status', ContentStatus::Approved->value);
    }

    /**
     * @return MorphOne<RatingAggregate, $this>
     */
    public function ratingAggregate(): MorphOne
    {
        return $this->morphOne(RatingAggregate::class, 'reviewable');
    }
}

<?php

namespace RatingsAndReviews\Concerns;

use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use RatingsAndReviews\Models\RatingAggregate;
use RatingsAndReviews\Models\Review;
use RatingsAndReviews\Support\ContentStatus;

/**
 * Relations for a host model implementing Reviewable. They are prefixed with `rr` so they
 * never clash with a host's own `reviews()` relation; the package itself never calls them
 * (it queries rr_reviews by morph type and id).
 */
trait HasReviews
{
    /**
     * @return MorphMany<Review, $this>
     */
    public function rrReviews(): MorphMany
    {
        return $this->morphMany(Review::class, 'reviewable');
    }

    /**
     * @return MorphMany<Review, $this>
     */
    public function rrApprovedReviews(): MorphMany
    {
        return $this->rrReviews()->where('status', ContentStatus::Approved->value);
    }

    /**
     * @return MorphOne<RatingAggregate, $this>
     */
    public function rrRatingAggregate(): MorphOne
    {
        return $this->morphOne(RatingAggregate::class, 'reviewable');
    }
}

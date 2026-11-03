<?php

namespace RatingsAndReviews\Events;

use Illuminate\Database\Eloquent\Model;
use RatingsAndReviews\Models\RatingAggregate;

/** The stored aggregates of a subject were recomputed (a host can copy them onto its own model). */
final readonly class RatingAggregatesRefreshed
{
    public function __construct(
        public Model $subject,
        public RatingAggregate $aggregate,
    ) {}
}

<?php

namespace RatingsAndReviews\Contracts;

use Illuminate\Database\Eloquent\Model;
use RatingsAndReviews\Models\RatingAggregate;
use RatingsAndReviews\Support\SectionSettings;

/**
 * Adds a key to the rating summary (for example the uncertainty band or the score trend).
 * Tag implementations with `ratings-and-reviews.summary-extensions` in the container.
 */
interface SummaryExtension
{
    public function key(): string;

    public function compute(Model&Reviewable $subject, ?RatingAggregate $aggregate, SectionSettings $settings): mixed;
}

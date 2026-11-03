<?php

namespace RatingsAndReviews\Defaults;

use Illuminate\Contracts\Auth\Authenticatable;
use RatingsAndReviews\Contracts\ContentAnnotator;

/** Default: no annotations. */
class NullContentAnnotator implements ContentAnnotator
{
    public function annotate(array $items, ?Authenticatable $viewer): array
    {
        return [];
    }
}

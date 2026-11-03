<?php

namespace RatingsAndReviews\Contracts;

use Illuminate\Contracts\Auth\Authenticatable;
use RatingsAndReviews\Models\Comment;
use RatingsAndReviews\Models\Review;
use RatingsAndReviews\Support\Annotation;

/**
 * Optional: marks reviews and comments with badges in the card header. A health-claim
 * warning, a "verified purchase" note, a "sample received" hint: the package knows none of
 * them, the host decides.
 *
 * Called once per list (a review page with the viewer's own review, a discussion page with
 * all its replies, a single saved item), never per item, so a host can detect or look up
 * in bulk. Key the result by `Services\Annotations::keyOf($item)` (`review:12`,
 * `comment:34`); an item without an entry has no annotations. Placeholders of removed
 * comments are not passed. The result is shown to every viewer of the item, so do not put
 * anything in it that only moderators may see (use `$viewer` for that).
 *
 * An exception is reported and the lists render without annotations (thrown instead when
 * `app.debug` is on).
 */
interface ContentAnnotator
{
    /**
     * @param  list<Review|Comment>  $items
     * @return array<string, list<Annotation>>
     */
    public function annotate(array $items, ?Authenticatable $viewer): array;
}

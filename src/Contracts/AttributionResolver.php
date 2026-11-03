<?php

namespace RatingsAndReviews\Contracts;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use RatingsAndReviews\Support\Attribution;

/**
 * Optional: posting "as" someone else (a brand, a shop, a chain) through the host's business
 * accounts. A comment or review sent with `attribution` (a key) is accepted only when the key
 * is among allowedFor() for that author and subject; the key, label and URL are stored with
 * the contribution as they were (so the badge survives the member leaving the team) and
 * shown as `attribution`. Default: switched off (isEnabled() is false; an attribution is 422).
 */
interface AttributionResolver
{
    public function isEnabled(): bool;

    /**
     * The attributions this member may post with on this subject (the Commentable, the
     * Reviewable, or the package Review for a reply to a review).
     *
     * @return list<Attribution>
     */
    public function allowedFor(Authenticatable $user, Model $subject): array;
}

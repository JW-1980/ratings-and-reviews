<?php

namespace RatingsAndReviews\Defaults;

use RatingsAndReviews\Contracts\OfficialReplyResolver;

/** Default: official replies are switched off. */
class NullOfficialReplyResolver implements OfficialReplyResolver
{
    public function isEnabled(): bool
    {
        return false;
    }

    public function repliesFor(array $reviews): array
    {
        return [];
    }
}

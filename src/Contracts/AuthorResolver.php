<?php

namespace RatingsAndReviews\Contracts;

use RatingsAndReviews\Support\AuthorProfile;

/**
 * Reads display name, avatar and profile URL of users. Batched so a discussion page costs
 * one lookup however many authors it shows.
 */
interface AuthorResolver
{
    /**
     * @param  list<int>  $userIds
     * @return array<int, AuthorProfile> keyed by user id; unknown ids may be missing.
     */
    public function resolve(array $userIds): array;
}

<?php

namespace RatingsAndReviews\Contracts;

use RatingsAndReviews\Support\VoteDimension;

/**
 * A package model readers can vote on (reviews and comments).
 */
interface Votable
{
    public function voteSection(): ?string;

    public function votableAuthorId(): ?int;

    /** Whether votes are accepted (the item is publicly visible). */
    public function acceptsVotes(): bool;

    /**
     * The counter column per dimension and value, e.g. ['agreement' => [1 => 'agree_count', -1 => 'disagree_count']].
     * A dimension or value missing here cannot be voted on this kind of item.
     *
     * @return array<string, array<int, string>>
     */
    public function voteCounterColumns(): array;

    /**
     * Derived columns recomputed after the counters change (e.g. controversy_score).
     *
     * @param  array<string, int>  $counters
     * @return array<string, mixed>
     */
    public function derivedVoteColumns(array $counters): array;

    public function supportsDimension(VoteDimension $dimension): bool;
}

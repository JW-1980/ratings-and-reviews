<?php

namespace RatingsAndReviews\Support;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use RatingsAndReviews\Models\Comment;

/**
 * One page of a discussion: top-level comments with their `replies` relation filled.
 */
final readonly class DiscussionPage
{
    /**
     * @param  Collection<int, Comment>  $roots
     * @param  array<int, array<string, int>>  $viewerVotes  comment id => [dimension => value]
     * @param  array<int, AuthorProfile>  $authors  user id => profile
     * @param  array<int, int>  $truncatedThreads  root id => total replies, for threads cut at the reply cap
     * @param  array<int, array{user_id: int, root_id: int, available: bool}>  $quotes  quoted comment id => what the quote links to
     * @param  array<int, int>  $pollChoices  poll id => the option the viewer chose
     * @param  Carbon|null  $newSince  the viewer's previous visit ("new since your last visit")
     */
    public function __construct(
        public SectionSettings $settings,
        public Collection $roots,
        public int $total,
        public int $page,
        public int $perPage,
        public DiscussionSort $sort,
        public array $viewerVotes,
        public array $authors,
        public ?int $topCommentId,
        public array $truncatedThreads,
        public array $quotes = [],
        public array $pollChoices = [],
        public ?Carbon $newSince = null,
    ) {}

    public function hasMore(): bool
    {
        return $this->total > $this->page * $this->perPage;
    }
}

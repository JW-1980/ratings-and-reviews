<?php

namespace RatingsAndReviews\Support;

use Illuminate\Database\Eloquent\Collection;
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
    ) {}

    public function hasMore(): bool
    {
        return $this->total > $this->page * $this->perPage;
    }
}

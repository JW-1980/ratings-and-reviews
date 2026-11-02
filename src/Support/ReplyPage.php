<?php

namespace RatingsAndReviews\Support;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use RatingsAndReviews\Models\Comment;

/**
 * One page of the replies of a thread (GET comments/{comment}/replies), flat and in tree
 * order: shallowest first, then oldest, so every reply's parent is either the root or came
 * earlier (on this page or a previous one).
 */
final readonly class ReplyPage
{
    /**
     * @param  Collection<int, Comment>  $replies
     * @param  array<int, array<string, int>>  $viewerVotes
     * @param  array<int, AuthorProfile>  $authors
     * @param  array<int, array{user_id: int, root_id: int, available: bool}>  $quotes
     * @param  array<int, int>  $pollChoices
     */
    public function __construct(
        public Comment $root,
        public SectionSettings $settings,
        public Collection $replies,
        public int $total,
        public ?string $nextCursor,
        public array $viewerVotes,
        public array $authors,
        public array $quotes,
        public array $pollChoices,
        public ?Carbon $newSince,
    ) {}
}

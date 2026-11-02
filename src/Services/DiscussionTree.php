<?php

namespace RatingsAndReviews\Services;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use RatingsAndReviews\Contracts\AuthorResolver;
use RatingsAndReviews\Contracts\Commentable;
use RatingsAndReviews\Models\Comment;
use RatingsAndReviews\Support\ContentStatus;
use RatingsAndReviews\Support\DiscussionPage;
use RatingsAndReviews\Support\DiscussionSort;

/**
 * Loads a discussion page in a fixed number of queries however deep it is: the root count,
 * one page of roots, every visible reply of those roots (they share `root_id`), the
 * viewer's votes and one batched author lookup. The tree is assembled in memory.
 *
 * Roots follow the chosen sort; replies read oldest first. A reply under a hidden or
 * deleted comment is left out with its subtree. Replies are fetched shallowest first, so
 * when a huge page hits `discussions.max_replies_per_page` the deepest replies are cut and
 * the cut threads are reported.
 */
class DiscussionTree
{
    public function __construct(
        private readonly SectionSettingsRepository $settings,
        private readonly VoteService $votes,
        private readonly AuthorResolver $authors,
    ) {}

    public function load(Model&Commentable $subject, ?string $voterKey, ?DiscussionSort $sort = null, int $page = 1): DiscussionPage
    {
        $settings = $this->settings->for($subject->commentSection());
        $sort ??= $settings->defaultSort;
        $page = max(1, $page);
        $perPage = max(1, (int) config('ratings-and-reviews.discussions.roots_per_page', 20));

        $rootQuery = $this->visibleIn($subject)->whereNull('parent_id');
        $total = (clone $rootQuery)->count();

        $roots = $this->sorted($rootQuery, $sort)
            ->offset(($page - 1) * $perPage)
            ->limit($perPage)
            ->get();

        [$all, $truncated] = $this->attachReplies($roots, $subject);

        $ids = $all->pluck('id')->map(fn (mixed $id): int => (int) $id)->all();
        $userIds = $all->pluck('user_id')->map(fn (mixed $id): int => (int) $id)->unique()->values()->all();

        return new DiscussionPage(
            settings: $settings,
            roots: $roots,
            total: $total,
            page: $page,
            perPage: $perPage,
            sort: $sort,
            viewerVotes: $this->votes->choicesFor((new Comment)->getMorphClass(), $ids, $voterKey),
            authors: $this->authors->resolve($userIds),
            topCommentId: $this->topComment($all, $settings->highlightTopComment, $settings->topCommentMinVotes),
            truncatedThreads: $truncated,
        );
    }

    /**
     * @return Builder<Comment>
     */
    private function visibleIn(Model $subject): Builder
    {
        return Comment::query()->forSubject($subject)->where('status', ContentStatus::Approved->value);
    }

    /**
     * @param  Builder<Comment>  $query
     * @return Builder<Comment>
     */
    private function sorted(Builder $query, DiscussionSort $sort): Builder
    {
        return match ($sort) {
            DiscussionSort::Newest => $query->orderByDesc('created_at')->orderByDesc('id'),
            DiscussionSort::Oldest => $query->orderBy('created_at')->orderBy('id'),
            DiscussionSort::Controversial => $query->orderByDesc('controversy_score')->orderByDesc('created_at')->orderByDesc('id'),
            DiscussionSort::Helpful => $query->orderByDesc('helpful_score')->orderByDesc('helpful_count')->orderByDesc('created_at')->orderByDesc('id'),
        };
    }

    /**
     * @param  Collection<int, Comment>  $roots
     * @return array{0: \Illuminate\Support\Collection<int, Comment>, 1: array<int, int>}
     */
    private function attachReplies(Collection $roots, Model&Commentable $subject): array
    {
        if ($roots->isEmpty()) {
            return [collect(), []];
        }

        $limit = max(1, (int) config('ratings-and-reviews.discussions.max_replies_per_page', 400));
        $replyQuery = $this->visibleIn($subject)->whereIn('root_id', $roots->modelKeys())->whereNotNull('parent_id');

        $replies = (clone $replyQuery)
            ->orderBy('depth')
            ->orderBy('created_at')
            ->orderBy('id')
            ->limit($limit)
            ->get();

        $truncated = [];

        if ($replies->count() >= $limit) {
            $loaded = $replies->countBy('root_id');
            $totals = $replyQuery->toBase()->selectRaw('root_id, COUNT(*) as total')->groupBy('root_id')->pluck('total', 'root_id');

            foreach ($totals as $rootId => $count) {
                if ((int) $count > (int) ($loaded[$rootId] ?? 0)) {
                    $truncated[(int) $rootId] = (int) $count;
                }
            }
        }

        $byParent = $replies->groupBy('parent_id');
        $all = collect();

        $attach = function (Comment $comment) use (&$attach, $byParent, $all, $subject): void {
            $comment->setRelation('commentable', $subject);
            $all->push($comment);
            $children = new Collection($byParent->get($comment->id, collect())->all());
            $children->each(function (Comment $child) use ($comment, $attach): void {
                $child->setRelation('parent', $comment);
                $attach($child);
            });
            $comment->setRelation('replies', $children);
        };

        $roots->each($attach);

        $byId = $all->keyBy('id');
        $all->each(function (Comment $comment) use ($byId): void {
            if ($comment->in_reply_to_id !== null && $byId->has($comment->in_reply_to_id)) {
                $comment->setRelation('inReplyTo', $byId->get($comment->in_reply_to_id));
            }
        });

        return [$all, $truncated];
    }

    /**
     * @param  \Illuminate\Support\Collection<int, Comment>  $comments
     */
    private function topComment(\Illuminate\Support\Collection $comments, bool $enabled, int $minVotes): ?int
    {
        if (! $enabled || $comments->count() < 2) {
            return null;
        }

        $best = $comments
            ->filter(fn (Comment $comment): bool => ! $comment->isRemovedByAuthor() && $comment->helpful_count >= $minVotes && $comment->helpful_score > 0)
            ->sortByDesc(fn (Comment $comment): float => $comment->helpful_score)
            ->first();

        return $best?->id;
    }
}

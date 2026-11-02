<?php

namespace RatingsAndReviews\Services;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use RatingsAndReviews\Contracts\AuthorResolver;
use RatingsAndReviews\Contracts\Commentable;
use RatingsAndReviews\Models\Comment;
use RatingsAndReviews\Models\Poll;
use RatingsAndReviews\Support\DiscussionPage;
use RatingsAndReviews\Support\DiscussionSort;

/**
 * Loads a discussion page in a fixed number of queries however deep it is: the root count,
 * one page of roots, every visible reply of those roots (they share `root_id`), the polls
 * of those comments, the viewer's votes and one batched author lookup (plus, only when
 * needed: the viewer's own unpublished roots, quoted comments and the viewer's poll votes).
 * The tree is assembled in memory.
 *
 * Visible: published comments (approved, `published_at` set). The viewer also sees their
 * own comments that are pending moderation or inside the undo window; their own unpublished
 * top-level comments are shown first on page 1 and are not part of `total`.
 *
 * Roots follow the chosen sort; replies read oldest first. A reply under a hidden or
 * deleted comment is left out with its subtree. Replies are fetched shallowest first, so
 * when a huge page hits `discussions.max_replies_per_page` the deepest replies are cut and
 * the cut threads are reported.
 *
 * Sorts: helpful (stored Wilson lower bound), newest, oldest and controversial (stored
 * `controversy_score` = (agree + disagree) ^ (min / max), recomputed by VoteService on
 * every vote; ties newest first).
 */
class DiscussionTree
{
    public function __construct(
        private readonly SectionSettingsRepository $settings,
        private readonly VoteService $votes,
        private readonly AuthorResolver $authors,
        private readonly PollService $polls,
    ) {}

    public function load(Model&Commentable $subject, ?string $voterKey, ?DiscussionSort $sort = null, int $page = 1, ?int $viewerId = null, ?Carbon $newSince = null): DiscussionPage
    {
        $settings = $this->settings->for($subject->commentSection());
        $sort ??= $settings->defaultSort;
        $page = max(1, $page);
        $perPage = max(1, (int) config('ratings-and-reviews.discussions.roots_per_page', 20));

        $rootQuery = Comment::query()->forSubject($subject)->published()->whereNull('parent_id');
        $total = (clone $rootQuery)->count();

        $roots = $this->sorted($rootQuery, $sort)
            ->offset(($page - 1) * $perPage)
            ->limit($perPage)
            ->get();

        if ($viewerId !== null && $page === 1) {
            $own = Comment::query()->forSubject($subject)->visibleTo($viewerId)
                ->where('user_id', $viewerId)
                ->whereNull('published_at')
                ->whereNull('parent_id')
                ->orderByDesc('created_at')
                ->orderByDesc('id')
                ->get();

            $roots = new Collection([...$own->all(), ...$roots->all()]);
        }

        [$all, $truncated] = $this->attachReplies($roots, $subject, $viewerId);

        $ids = $all->pluck('id')->map(fn (mixed $id): int => (int) $id)->all();
        $quotes = $this->quotedComments($all);
        $userIds = $all->pluck('user_id')->merge(array_column($quotes, 'user_id'))->map(fn (mixed $id): int => (int) $id)->unique()->values()->all();
        $pollIds = $this->attachPolls($all, $ids);

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
            quotes: $quotes,
            pollChoices: $this->polls->choicesFor($pollIds, $voterKey),
            newSince: $newSince,
        );
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
    private function attachReplies(Collection $roots, Model&Commentable $subject, ?int $viewerId): array
    {
        if ($roots->isEmpty()) {
            return [collect(), []];
        }

        $limit = max(1, (int) config('ratings-and-reviews.discussions.max_replies_per_page', 400));
        $replyQuery = Comment::query()->forSubject($subject)->visibleTo($viewerId)->whereIn('root_id', $roots->modelKeys())->whereNotNull('parent_id');

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
     * The comments quoted on this page, in one query (none when nothing is quoted).
     *
     * @param  \Illuminate\Support\Collection<int, Comment>  $comments
     * @return array<int, array{user_id: int, root_id: int, available: bool}>
     */
    private function quotedComments(\Illuminate\Support\Collection $comments): array
    {
        $ids = $comments->pluck('quoted_comment_id')->filter()->map(fn (mixed $id): int => (int) $id)->unique()->values()->all();

        if ($ids === []) {
            return [];
        }

        $quotes = [];

        foreach (Comment::query()->withTrashed()->whereKey($ids)->get() as $quoted) {
            $quotes[$quoted->id] = [
                'user_id' => $quoted->user_id,
                'root_id' => $quoted->root_id ?? $quoted->id,
                'available' => $quoted->isPubliclyVisible(),
            ];
        }

        return $quotes;
    }

    /**
     * Sets the `poll` relation (with options) on the comments that have one.
     *
     * @param  \Illuminate\Support\Collection<int, Comment>  $comments
     * @param  list<int>  $ids
     * @return list<int> poll ids
     */
    private function attachPolls(\Illuminate\Support\Collection $comments, array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $polls = Poll::query()->whereIn('comment_id', $ids)->with('options')->get()->keyBy('comment_id');

        $comments->each(fn (Comment $comment) => $comment->setRelation('poll', $polls->get($comment->id)));

        return $polls->pluck('id')->map(fn (mixed $id): int => (int) $id)->values()->all();
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
            ->filter(fn (Comment $comment): bool => $comment->isPubliclyVisible() && $comment->counted && $comment->helpful_count >= $minVotes && $comment->helpful_score > 0)
            ->sortByDesc(fn (Comment $comment): float => $comment->helpful_score)
            ->first();

        return $best?->id;
    }
}

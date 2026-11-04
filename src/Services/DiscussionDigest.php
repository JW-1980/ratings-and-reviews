<?php

namespace RatingsAndReviews\Services;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use RatingsAndReviews\Contracts\AuthorResolver;
use RatingsAndReviews\Contracts\Commentable;
use RatingsAndReviews\Models\Comment;
use RatingsAndReviews\Support\AuthorProfile;
use RatingsAndReviews\Support\Excerpt;
use RatingsAndReviews\Support\VoteDimension;

/**
 * The TL;DR of a long discussion, without AI: purely extractive and count-based.
 *
 * From the section's `digest_threshold` of public, counted comments (0 switches it off):
 * - totals: comments, participants, agree and disagree votes and the agree share;
 * - `most_agreed`: the `digest.per_side` comments with the highest net agreement
 *   (agree − disagree > 0), and `most_disagreed`: those with the highest net disagreement
 *   (the two never overlap); ties: more votes first, then oldest;
 * - `most_helpful`: the comment with the highest Wilson lower bound of helpfulness;
 * - each comment shown by the first sentence of its text (`digest.excerpt_length`).
 * Lists of a vote dimension the section does not use are empty (or null).
 */
class DiscussionDigest
{
    public function __construct(
        private readonly SectionSettingsRepository $settings,
        private readonly AuthorResolver $authors,
        private readonly Participation $participation,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function for(Model&Commentable $subject, ?int $viewerId = null): array
    {
        $settings = $this->settings->for($subject->commentSectionKey());
        $threshold = $settings->digestThreshold;
        $base = Comment::query()->forSubject($subject)->published()->withContent()->where('counted', true)
            ->withoutAuthors($this->participation->hiddenAuthorIds($subject, $viewerId));
        $total = (clone $base)->count();

        $digest = ['available' => $threshold > 0 && $total >= $threshold, 'threshold' => $threshold, 'total_comments' => $total];

        if (! $digest['available']) {
            return $digest + ['participants' => null, 'agreement' => null, 'most_agreed' => [], 'most_disagreed' => [], 'most_helpful' => null];
        }

        $perSide = max(1, (int) config('ratings-and-reviews.digest.per_side', 3));
        $agreement = $settings->allowsDimension(VoteDimension::Agreement);
        $helpfulness = $settings->allowsDimension(VoteDimension::Helpfulness);

        $totals = (clone $base)->toBase()->selectRaw('COUNT(DISTINCT user_id) as participants, COALESCE(SUM(agree_count), 0) as agree, COALESCE(SUM(disagree_count), 0) as disagree')->first();
        $agree = (int) ($totals->agree ?? 0);
        $disagree = (int) ($totals->disagree ?? 0);

        $agreed = $agreement ? $this->ranked(clone $base, 'agree_count', 'disagree_count', $perSide) : collect();
        $disagreed = $agreement ? $this->ranked(clone $base, 'disagree_count', 'agree_count', $perSide) : collect();
        $helpful = $helpfulness
            ? (clone $base)->where('helpful_count', '>', 0)->where('helpful_score', '>', 0)->orderByDesc('helpful_score')->orderByDesc('helpful_count')->orderBy('id')->first()
            : null;

        $profiles = $this->authors->resolve($agreed->merge($disagreed)->push($helpful)->filter()->pluck('user_id')->map(fn (mixed $id): int => (int) $id)->unique()->values()->all());
        $present = fn (Comment $comment): array => $this->present($comment, $profiles);

        return $digest + [
            'participants' => (int) ($totals->participants ?? 0),
            'agreement' => $agreement ? [
                'agree' => $agree,
                'disagree' => $disagree,
                'agree_share' => $agree + $disagree === 0 ? null : round($agree / ($agree + $disagree), 4),
            ] : null,
            'most_agreed' => $agreed->map($present)->values()->all(),
            'most_disagreed' => $disagreed->map($present)->values()->all(),
            'most_helpful' => $helpful === null ? null : $present($helpful),
        ];
    }

    /**
     * @param  Builder<Comment>  $query
     * @return Collection<int, Comment>
     */
    private function ranked(Builder $query, string $for, string $against, int $limit): Collection
    {
        return $query->whereColumn($for, '>', $against)
            ->orderByRaw("(`{$for}` - `{$against}`) DESC")
            ->orderByRaw("(`{$for}` + `{$against}`) DESC")
            ->orderBy('created_at')
            ->orderBy('id')
            ->limit($limit)
            ->get();
    }

    /**
     * @param  array<int, AuthorProfile>  $profiles
     * @return array<string, mixed>
     */
    private function present(Comment $comment, array $profiles): array
    {
        return [
            'id' => $comment->id,
            'root_id' => $comment->root_id ?? $comment->id,
            'excerpt' => Excerpt::firstSentence($comment->body_text, (int) config('ratings-and-reviews.digest.excerpt_length', 200)),
            'author' => ($profiles[$comment->user_id] ?? null)?->toArray(),
            'agree_count' => $comment->agree_count,
            'disagree_count' => $comment->disagree_count,
            'helpful_count' => $comment->helpful_count,
            'created_at' => $comment->created_at?->toIso8601String(),
        ];
    }
}

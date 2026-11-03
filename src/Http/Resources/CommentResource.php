<?php

namespace RatingsAndReviews\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Carbon;
use RatingsAndReviews\Models\Comment;
use RatingsAndReviews\Models\Poll;
use RatingsAndReviews\Services\PollService;
use RatingsAndReviews\Support\Attribution;
use RatingsAndReviews\Support\AuthorProfile;
use RatingsAndReviews\Support\SectionSettings;

/**
 * A comment with its replies (when the `replies` relation is set by DiscussionTree).
 *
 * `visibility` is `public`, `pending` (awaiting a moderator) or `scheduled` (inside the
 * undo window, `publish_at` says until when); only the author is shown the last two.
 * `is_new` marks comments by others published after the viewer's previous visit.
 * `attribution` is the identity it was posted as (a brand, a shop), `is_pinned` the one
 * pinned top-level comment, `is_blocked` an author in a block relation with the viewer.
 *
 * @property Comment $resource
 */
class CommentResource extends JsonResource
{
    /** @var array<int, AuthorProfile> */
    private array $authors = [];

    /** @var array<int, array<string, int>> */
    private array $viewerVotes = [];

    private ?int $viewerId = null;

    private bool $viewerModerates = false;

    private ?SectionSettings $settings = null;

    private ?int $topCommentId = null;

    /** @var array<int, array{user_id: int|null, root_id: int, available: bool, withheld?: bool}> */
    private array $quotes = [];

    /** @var array<int, int> */
    private array $pollChoices = [];

    private ?Carbon $newSince = null;

    /** @var list<int> members in a block relation with the viewer */
    private array $blockedIds = [];

    private bool $viewerPins = false;

    /**
     * @param  array<int, AuthorProfile>  $authors
     * @param  array<int, array<string, int>>  $viewerVotes
     */
    public function withContext(array $authors, array $viewerVotes, ?int $viewerId, bool $viewerModerates, ?SectionSettings $settings, ?int $topCommentId = null): static
    {
        $this->authors = $authors;
        $this->viewerVotes = $viewerVotes;
        $this->viewerId = $viewerId;
        $this->viewerModerates = $viewerModerates;
        $this->settings = $settings;
        $this->topCommentId = $topCommentId;

        return $this;
    }

    /**
     * @param  array<int, array{user_id: int|null, root_id: int, available: bool, withheld?: bool}>  $quotes
     * @param  array<int, int>  $pollChoices
     */
    public function withExtras(array $quotes, array $pollChoices, ?Carbon $newSince): static
    {
        $this->quotes = $quotes;
        $this->pollChoices = $pollChoices;
        $this->newSince = $newSince;

        return $this;
    }

    /**
     * The viewer's blocks (BlockList) and whether they may pin comments here (moderator or
     * discussion owner).
     *
     * @param  list<int>  $blockedIds
     */
    public function withParticipation(array $blockedIds, bool $viewerPins): static
    {
        $this->blockedIds = $blockedIds;
        $this->viewerPins = $viewerPins;

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $comment = $this->resource;
        $removed = $comment->isPlaceholder();
        $own = $this->viewerId !== null && $this->viewerId === $comment->user_id;
        $blocked = ! $own && $comment->user_id !== null && in_array($comment->user_id, $this->blockedIds, true);
        $dimensions = $this->settings?->voteDimensions ?? [];

        $counts = [];

        foreach ($comment->voteCounterColumns() as $dimension => $columns) {
            if ($this->settings === null || in_array($dimension, array_map(fn ($case) => $case->value, $dimensions), true)) {
                foreach ($columns as $value => $column) {
                    $counts[$dimension][$value === 1 ? 'up' : 'down'] = (int) $comment->getAttribute($column);
                }
            }
        }

        return [
            'id' => $comment->id,
            'parent_id' => $comment->parent_id,
            'root_id' => $comment->root_id,
            'in_reply_to_id' => $comment->in_reply_to_id,
            'in_reply_to_author' => $comment->in_reply_to_id !== null ? $this->replyTargetAuthor() : null,
            'depth' => $comment->depth,
            'status' => $comment->status->value,
            'visibility' => $comment->visibility(),
            'publish_at' => $comment->published_at === null ? $comment->publish_at?->toIso8601String() : null,
            'published_at' => $comment->published_at?->toIso8601String(),
            'is_new' => ! $own && $this->newSince !== null && $comment->published_at !== null && $comment->published_at->gt($this->newSince),
            'author' => $removed || $comment->user_id === null ? null : ($this->authors[$comment->user_id] ?? null)?->toArray(),
            'body_html' => $removed ? '' : $comment->body_html,
            'is_removed' => $removed,
            'removed_label' => match (true) {
                $comment->isRemovedByAuthor() && $comment->user_id === null => __('ratings-and-reviews::messages.comment_erased_placeholder'),
                $comment->isRemovedByAuthor() => __('ratings-and-reviews::messages.comment_removed_placeholder'),
                $comment->isErasureWithheld() => __('ratings-and-reviews::messages.comment_withheld_placeholder'),
                default => null,
            },
            // Posted "as" a brand or shop (AttributionResolver); shown as a badge.
            'attribution' => $removed ? null : Attribution::present($comment->attribution),
            'is_pinned' => $comment->pinned_at !== null && $comment->parent_id === null,
            'pinned_at' => $comment->parent_id === null ? $comment->pinned_at?->toIso8601String() : null,
            // The author is in a block relation with the viewer: no reply, quote or vote.
            'is_blocked' => $blocked,
            'quote' => $removed || $comment->quoted_comment_id === null ? null : $this->quote(),
            'poll' => ! $removed && $comment->relationLoaded('poll') && $comment->poll instanceof Poll
                ? PollService::present($comment->poll, $this->pollChoices[$comment->poll->id] ?? null)
                : null,
            'is_edited' => $comment->is_edited,
            'is_top' => $this->topCommentId === $comment->id,
            'is_collapsed' => $this->settings?->shouldCollapse($comment->helpful_count, $comment->unhelpful_count) ?? false,
            'counts' => (object) $counts,
            'helpful_score' => round($comment->helpful_score, 6),
            'controversy_score' => round($comment->controversy_score, 6),
            'viewer_votes' => (object) ($this->viewerVotes[$comment->id] ?? []),
            'is_own' => $own,
            'can' => [
                'edit' => ! $removed && (($own && $this->withinEditWindow()) || $this->viewerModerates),
                'delete' => ! $removed && ($own || $this->viewerModerates),
                'reply' => ! $removed && ! $blocked && $comment->isPubliclyVisible(),
                'vote' => ! $removed && ! $own && ! $blocked && $comment->isPubliclyVisible(),
                'pin' => $this->viewerPins && ! $removed && $comment->parent_id === null && $comment->isPubliclyVisible(),
                'undo' => $own && $comment->isUndoable(),
            ],
            'created_at' => $comment->created_at?->toIso8601String(),
            'edited_at' => $comment->edited_at?->toIso8601String(),
            'replies' => $comment->relationLoaded('replies')
                ? $comment->replies->map(fn (Comment $reply): array => (new self($reply))
                    ->withContext($this->authors, $this->viewerVotes, $this->viewerId, $this->viewerModerates, $this->settings, $this->topCommentId)
                    ->withExtras($this->quotes, $this->pollChoices, $this->newSince)
                    ->withParticipation($this->blockedIds, $this->viewerPins)
                    ->resolve($request))->values()->all()
                : [],
        ];
    }

    /**
     * @return array{comment_id: int, root_id: int|null, text: string|null, author: array<string, mixed>|null, available: bool}
     */
    private function quote(): array
    {
        $comment = $this->resource;
        $quoted = $this->quotes[(int) $comment->quoted_comment_id] ?? null;

        return [
            'comment_id' => (int) $comment->quoted_comment_id,
            'root_id' => $quoted['root_id'] ?? null,
            // The quoted words are the quoted author's: hidden while their erasure request is pending.
            'text' => ($quoted['withheld'] ?? false) ? null : $comment->quote_text,
            'author' => $quoted !== null && $quoted['available'] && $quoted['user_id'] !== null ? ($this->authors[$quoted['user_id']] ?? null)?->toArray() : null,
            'available' => $quoted['available'] ?? false,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function replyTargetAuthor(): ?array
    {
        $target = $this->resource->relationLoaded('inReplyTo') ? $this->resource->inReplyTo : null;

        return $target !== null && ! $target->isPlaceholder() && $target->user_id !== null ? ($this->authors[$target->user_id] ?? null)?->toArray() : null;
    }

    private function withinEditWindow(): bool
    {
        $created = $this->resource->created_at;

        return $this->settings !== null && $this->settings->enabled && (
            $this->settings->editWindowMinutes === null
            || ($created !== null && $created->copy()->addMinutes($this->settings->editWindowMinutes)->isFuture())
        );
    }
}

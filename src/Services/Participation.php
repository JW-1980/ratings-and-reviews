<?php

namespace RatingsAndReviews\Services;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;
use RatingsAndReviews\Contracts\AbilityResolver;
use RatingsAndReviews\Contracts\AttributionResolver;
use RatingsAndReviews\Contracts\BlockList;
use RatingsAndReviews\Contracts\Commentable;
use RatingsAndReviews\Contracts\HasDiscussionContext;
use RatingsAndReviews\Contracts\HasDiscussionOwner;
use RatingsAndReviews\Contracts\HidesBlockedMembers;
use RatingsAndReviews\Contracts\KindAwareParticipationRules;
use RatingsAndReviews\Contracts\ParticipationRules;
use RatingsAndReviews\Contracts\Reviewable;
use RatingsAndReviews\Contracts\ScopedModeration;
use RatingsAndReviews\Models\Comment;
use RatingsAndReviews\Models\Review;
use RatingsAndReviews\Models\ReviewFollowUp;
use RatingsAndReviews\Support\Ability;
use RatingsAndReviews\Support\Attribution;
use RatingsAndReviews\Support\ParticipationKind;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Throwable;

/**
 * The subject's optional participation hooks in one place: who may write
 * (KindAwareParticipationRules per kind, else ParticipationRules), forced or skipped pre-moderation, scoped moderators
 * (ScopedModeration, combined with `rr.moderate`), the discussion owner, the context URL,
 * member blocks (BlockList) and whether blocked members are hidden (HidesBlockedMembers).
 * Each hook is optional; without it the package behaves as before.
 */
class Participation
{
    /** @var array<int, list<int>> blockedWith() per user, for the request */
    private array $blocked = [];

    public function __construct(
        private readonly AbilityResolver $abilities,
        private readonly BlockList $blocks,
        private readonly AttributionResolver $attributions,
    ) {}

    /**
     * Whether the user moderates the item or subject: `rr.moderate` with it, or a scoped
     * moderator of the subject it belongs to.
     */
    public function mayModerate(?Authenticatable $user, mixed $target): bool
    {
        if ($user === null) {
            return false;
        }

        if ($this->abilities->allows($user, Ability::MODERATE, $target)) {
            return true;
        }

        $subject = $this->subjectOf($target);

        return $subject instanceof ScopedModeration && $subject->canModerate($user);
    }

    /**
     * The subject a package item belongs to (a Comment's commentable, a Review's reviewable),
     * or the target itself when it is a subject.
     */
    public function subjectOf(mixed $target): ?Model
    {
        $subject = match (true) {
            $target instanceof Comment => $target->commentable,
            $target instanceof ReviewFollowUp => $target->review?->reviewable,
            $target instanceof Review => $target->reviewable,
            default => $target,
        };

        return $subject instanceof Model && ($subject instanceof Commentable || $subject instanceof Reviewable) ? $subject : null;
    }

    /**
     * Whether the member may write a contribution of this kind on the subject: the subject's
     * KindAwareParticipationRules when it has them, else ParticipationRules::canParticipate(),
     * else yes. A comment on a package Review is a `review_reply` (the Review asks its
     * reviewed subject).
     *
     * @param  ParticipationKind::*  $kind
     */
    public function canParticipate(Authenticatable $user, Model $subject, string $kind = ParticipationKind::COMMENT): bool
    {
        if ($subject instanceof Review && $kind === ParticipationKind::COMMENT) {
            $kind = ParticipationKind::REVIEW_REPLY;
        }

        if ($subject instanceof KindAwareParticipationRules) {
            return $subject->canParticipateIn($user, $kind);
        }

        return ! $subject instanceof ParticipationRules || $subject->canParticipate($user);
    }

    /**
     * @param  ParticipationKind::*  $kind
     *
     * @throws AccessDeniedHttpException when the member may not write on the subject
     */
    public function assertCanParticipate(Authenticatable $user, Model $subject, string $kind = ParticipationKind::COMMENT): void
    {
        if (! $this->canParticipate($user, $subject, $kind)) {
            throw new AccessDeniedHttpException((string) __('ratings-and-reviews::messages.participation_denied'));
        }
    }

    /** The subject's pre-moderation decision for this author (ParticipationRules), or null. */
    public function holdOverride(Model $subject, Authenticatable $author): ?bool
    {
        return $subject instanceof ParticipationRules ? $subject->holdsContributionFrom($author) : null;
    }

    public function ownerId(Model $subject): ?int
    {
        return $subject instanceof HasDiscussionOwner ? $subject->discussionOwnerId() : null;
    }

    public function contextUrl(Model&Commentable $subject): ?string
    {
        return $subject instanceof HasDiscussionContext ? $subject->discussionContextUrl() : $subject->discussionUrl();
    }

    public function blocksEnabled(): bool
    {
        return $this->blocks->isEnabled();
    }

    /** Whether the two members are in a block relation (never for a missing member or oneself). */
    public function isBlocked(?int $userId, ?int $otherUserId): bool
    {
        if ($userId === null || $otherUserId === null || $userId === $otherUserId || ! $this->blocks->isEnabled()) {
            return false;
        }

        return in_array($otherUserId, $this->blockedWith($userId), true);
    }

    /**
     * Everyone in a block relation with the member (asked once per request and member).
     *
     * @return list<int>
     */
    public function blockedWith(?int $userId): array
    {
        if ($userId === null || ! $this->blocks->isEnabled()) {
            return [];
        }

        return $this->blocked[$userId] ??= array_values(array_unique(array_map('intval', $this->blocks->blockedWith($userId))));
    }

    /**
     * The members whose contributions are left out for this viewer on this subject.
     *
     * @return list<int>
     */
    public function hiddenAuthorIds(Model $subject, ?int $viewerId): array
    {
        return $viewerId !== null && $subject instanceof HidesBlockedMembers && $subject->hidesBlockedMembers()
            ? $this->blockedWith($viewerId)
            : [];
    }

    /**
     * The identities the member may post as on the subject (AttributionResolver), as
     * snapshots; a failing resolver is reported and offers none.
     *
     * @return list<array{key: string, label: string, url: string|null}>
     */
    public function attributionOptions(Authenticatable $user, Model $subject): array
    {
        if (! $this->attributions->isEnabled()) {
            return [];
        }

        try {
            $allowed = $this->attributions->allowedFor($user, $subject);
        } catch (Throwable $exception) {
            report($exception);

            return [];
        }

        return array_values(array_filter(array_map(fn (mixed $item): ?array => $item instanceof Attribution ? $item->toArray() : null, $allowed)));
    }

    /**
     * The attribution snapshot for `$key`, which must be one the AttributionResolver allows
     * the author on this subject. Null or '' posts as the member.
     *
     * @return array{key: string, label: string, url: string|null}|null
     *
     * @throws ValidationException
     */
    public function attribution(Authenticatable $author, Model $subject, mixed $key): ?array
    {
        if ($key === null || $key === '') {
            return null;
        }

        foreach (is_string($key) ? $this->attributionOptions($author, $subject) : [] as $allowed) {
            if ($allowed['key'] === $key) {
                return $allowed;
            }
        }

        throw ValidationException::withMessages(['attribution' => __('ratings-and-reviews::messages.attribution_invalid')]);
    }

    /**
     * Who should hear about a newly public comment: the author of the comment it answers
     * (reason `reply`) or, for a top-level comment, the discussion owner (reason `owner`).
     * Never the comment's own author nor anyone in a block relation with them.
     *
     * @return list<array{user_id: int, reason: string}>
     */
    public function recipientsFor(Comment $comment): array
    {
        $authorId = $comment->user_id;
        $targetId = $comment->in_reply_to_id ?? $comment->parent_id;

        if ($targetId !== null) {
            $recipient = Comment::query()->withTrashed()->whereKey($targetId)->value('user_id');
            $reason = 'reply';
        } else {
            $subject = $comment->commentable;
            $recipient = $subject instanceof Model ? $this->ownerId($subject) : null;
            $reason = 'owner';
        }

        $recipient = $recipient === null ? null : (int) $recipient;

        if ($recipient === null || $recipient === $authorId || $this->isBlocked($authorId, $recipient)) {
            return [];
        }

        return [['user_id' => $recipient, 'reason' => $reason]];
    }
}

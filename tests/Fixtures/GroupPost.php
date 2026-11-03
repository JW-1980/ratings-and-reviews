<?php

namespace RatingsAndReviews\Tests\Fixtures;

use Illuminate\Contracts\Auth\Authenticatable;
use RatingsAndReviews\Contracts\HasDiscussionContext;
use RatingsAndReviews\Contracts\HasDiscussionOwner;
use RatingsAndReviews\Contracts\HidesBlockedMembers;
use RatingsAndReviews\Contracts\ParticipationRules;
use RatingsAndReviews\Contracts\ScopedModeration;
use RatingsAndReviews\Contracts\SyncsCommentCount;

/**
 * A host subject with every optional participation hook, as a group post would have them:
 * only members write, an owner, scoped moderators, its own comment counter and hidden
 * blocked members. The hooks read static lists the tests fill (reset in setUp).
 *
 * @property int|null $owner_id
 * @property int $comment_count
 */
class GroupPost extends Product implements HasDiscussionContext, HasDiscussionOwner, HidesBlockedMembers, ParticipationRules, ScopedModeration, SyncsCommentCount
{
    /** @var list<int>|null null: everyone may participate */
    public static ?array $members = null;

    /** @var array<int, bool|null> user id => holdsContributionFrom() */
    public static array $holds = [];

    /** @var array<int, list<int>> post id => its moderators */
    public static array $moderators = [];

    public static bool $hideBlocked = true;

    public static function reset(): void
    {
        self::$members = null;
        self::$holds = [];
        self::$moderators = [];
        self::$hideBlocked = true;
    }

    public function canParticipate(Authenticatable $user): bool
    {
        return self::$members === null || in_array((int) $user->getAuthIdentifier(), self::$members, true);
    }

    public function holdsContributionFrom(Authenticatable $author): ?bool
    {
        return self::$holds[(int) $author->getAuthIdentifier()] ?? null;
    }

    public function canModerate(Authenticatable $user): bool
    {
        return in_array((int) $user->getAuthIdentifier(), self::$moderators[$this->id] ?? [], true);
    }

    public function discussionOwnerId(): ?int
    {
        return $this->owner_id;
    }

    public function discussionContextUrl(): ?string
    {
        return '/groups/'.$this->id;
    }

    public function syncCommentCount(int $publicComments): void
    {
        $this->forceFill(['comment_count' => $publicComments])->saveQuietly();
    }

    public function hidesBlockedMembers(): bool
    {
        return self::$hideBlocked;
    }
}

<?php

namespace RatingsAndReviews\Services;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use InvalidArgumentException;
use RatingsAndReviews\Contracts\Reviewable;
use RatingsAndReviews\Events\ReviewInvitationDue;
use RatingsAndReviews\Models\Review;
use RatingsAndReviews\Models\ReviewInvitation;

/**
 * Invitations to review a subject some days after a purchase (a scanned receipt, a ticked
 * shopping list, "taken into use"). The host calls schedule(); `rr:send-review-invitations`
 * (hourly) fires ReviewInvitationDue for every invitation that is due, and the host sends
 * it through its own channels. One invitation per user and subject (unique index): calling
 * schedule() again keeps the first. An invitation is completed as soon as the user's review
 * exists, and is never sent after that or after the user dismissed it.
 */
class ReviewInvitations
{
    public function __construct(private readonly Dispatcher $events) {}

    /**
     * @return ReviewInvitation|null null when the user already reviewed the subject.
     *
     * @throws InvalidArgumentException on an invalid source or delay
     */
    public function schedule(Authenticatable|int $user, Model&Reviewable $reviewable, string $source, int $delayDays): ?ReviewInvitation
    {
        $userId = $user instanceof Authenticatable ? (int) $user->getAuthIdentifier() : $user;
        $maxDelay = max(0, (int) config('ratings-and-reviews.invitations.max_delay_days', 365));

        if (preg_match('/^[a-z0-9_.\-]{1,64}$/', $source) !== 1) {
            throw new InvalidArgumentException('The invitation source must be 1–64 characters of a-z, 0-9, _, . or -.');
        }

        if ($delayDays < 0 || $delayDays > $maxDelay) {
            throw new InvalidArgumentException("The invitation delay must be 0–{$maxDelay} days.");
        }

        if ($this->hasReview($userId, $reviewable->getMorphClass(), $reviewable->getKey())) {
            return null;
        }

        $keys = ['user_id' => $userId, 'reviewable_type' => $reviewable->getMorphClass(), 'reviewable_id' => $reviewable->getKey()];

        ReviewInvitation::query()->insertOrIgnore($keys + [
            'source' => $source,
            'due_at' => now()->addDays($delayDays),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return ReviewInvitation::query()->where($keys)->first();
    }

    /** Marks the user's invitation for this subject completed (their review now exists). */
    public function complete(int $userId, string $reviewableType, int|string $reviewableId): void
    {
        ReviewInvitation::query()
            ->where('user_id', $userId)
            ->where('reviewable_type', $reviewableType)
            ->where('reviewable_id', $reviewableId)
            ->whereNull('completed_at')
            ->update(['completed_at' => now(), 'updated_at' => now()]);
    }

    public function dismiss(ReviewInvitation $invitation): ReviewInvitation
    {
        if ($invitation->dismissed_at === null) {
            $invitation->forceFill(['dismissed_at' => now()])->save();
        }

        return $invitation;
    }

    /**
     * Fires ReviewInvitationDue for each due invitation, at most once each: the invitation is
     * claimed with a conditional update (sent_at IS NULL), so overlapping runs cannot send it
     * twice. Invitations whose review already exists are completed instead; those whose
     * subject is gone are removed; those whose subject does not accept reviews right now
     * wait for a later run.
     *
     * @return array{sent: int, completed: int, removed: int, waiting: int}
     */
    public function sendDue(?int $limit = null): array
    {
        $limit ??= max(1, (int) config('ratings-and-reviews.invitations.batch_size', 500));
        $result = ['sent' => 0, 'completed' => 0, 'removed' => 0, 'waiting' => 0];

        $due = ReviewInvitation::query()
            ->where('due_at', '<=', now())
            ->whereNull('sent_at')
            ->whereNull('completed_at')
            ->whereNull('dismissed_at')
            ->orderBy('due_at')
            ->orderBy('id')
            ->limit($limit)
            ->get();

        // Per run, not per invitation: who already reviewed, and the subjects.
        $reviewed = $this->reviewedKeys($due);
        $subjects = $this->subjectsOf($due);

        foreach ($due as $invitation) {
            if (isset($reviewed[$this->key($invitation->reviewable_type, $invitation->reviewable_id, $invitation->user_id)])) {
                $this->complete($invitation->user_id, $invitation->reviewable_type, $invitation->reviewable_id);
                $result['completed']++;

                continue;
            }

            $subject = $subjects[$invitation->reviewable_type][(string) $invitation->reviewable_id] ?? null;

            if (! $subject instanceof Reviewable) {
                $invitation->delete();
                $result['removed']++;

                continue;
            }

            if (! $subject->acceptsReviews() || ! app(SectionSettingsRepository::class)->for($subject->reviewSectionKey())->reviewsEnabled) {
                // Move the invitation out of the way of later ones, so a long run of closed
                // subjects cannot starve invitations that are ready. It stays due for a later run.
                $invitation->forceFill(['due_at' => now()->addMinutes(max(1, (int) config('ratings-and-reviews.invitations.waiting_retry_minutes', 60)))])->save();
                $result['waiting']++;

                continue;
            }

            // Whole seconds, as the column stores them, so the model equals the row.
            $sentAt = now()->startOfSecond();
            $claimed = ReviewInvitation::query()->whereKey($invitation->id)->whereNull('sent_at')
                ->update(['sent_at' => $sentAt, 'updated_at' => $sentAt]);

            if ($claimed === 1) {
                $invitation->forceFill(['sent_at' => $sentAt, 'updated_at' => $sentAt])->syncOriginal();
                $invitation->setRelation('reviewable', $subject);
                $this->events->dispatch(new ReviewInvitationDue($invitation));
                $result['sent']++;
            }
        }

        return $result;
    }

    /**
     * The due invitations whose user already reviewed the subject, as a set of key(): one
     * query per subject type and 500 invitations instead of one per invitation.
     *
     * @param  Collection<int, ReviewInvitation>  $invitations
     * @return array<string, true>
     */
    private function reviewedKeys(Collection $invitations): array
    {
        $reviewed = [];

        foreach ($invitations->groupBy('reviewable_type') as $type => $group) {
            foreach ($group->chunk(500) as $chunk) {
                // Every pair of these subjects and users: more than asked, never less.
                $rows = Review::query()
                    ->where('reviewable_type', $type)
                    ->whereIn('reviewable_id', $chunk->pluck('reviewable_id')->unique()->values()->all())
                    ->whereIn('user_id', $chunk->pluck('user_id')->unique()->values()->all())
                    ->toBase()
                    ->get(['reviewable_id', 'user_id']);

                foreach ($rows as $row) {
                    $reviewed[$this->key((string) $type, $row->reviewable_id, $row->user_id)] = true;
                }
            }
        }

        return $reviewed;
    }

    /**
     * The subjects of the due invitations: [morph type => [id => model]], one query per type.
     * A type that is no longer in the morph map, or a deleted subject, is simply absent.
     *
     * @param  Collection<int, ReviewInvitation>  $invitations
     * @return array<string, array<string, Model>>
     */
    private function subjectsOf(Collection $invitations): array
    {
        $subjects = [];

        foreach ($invitations->groupBy('reviewable_type') as $type => $group) {
            $class = Relation::getMorphedModel((string) $type);

            if ($class === null || ! is_subclass_of($class, Model::class)) {
                continue;
            }

            foreach ($group->pluck('reviewable_id')->unique()->chunk(500) as $ids) {
                foreach ($class::query()->whereKey($ids->values()->all())->get() as $subject) {
                    $subjects[(string) $type][(string) $subject->getKey()] = $subject;
                }
            }
        }

        return $subjects;
    }

    private function key(string $type, int|string $subjectId, int|string $userId): string
    {
        return $type.'|'.$subjectId.'|'.$userId;
    }

    private function hasReview(int $userId, string $type, int|string $id): bool
    {
        return Review::query()->where('user_id', $userId)->where('reviewable_type', $type)->where('reviewable_id', $id)->exists();
    }
}

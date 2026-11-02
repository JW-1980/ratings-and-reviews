<?php

namespace RatingsAndReviews\Services;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RatingsAndReviews\Models\Comment;
use RatingsAndReviews\Models\DiscussionState;
use RatingsAndReviews\Support\SectionSettings;

/**
 * Per-discussion state set by moderators: slow mode (overriding the section's
 * `slow_mode_seconds`; null in the row means "use the section") and locking.
 *
 * Slow mode is checked inside the posting transaction after the discussion's state row is
 * locked (SELECT … FOR UPDATE), so two posts of the same member arriving together are
 * serialised. The member's last comment is then read with a locking read too: under
 * REPEATABLE READ a plain read would use the transaction's earlier snapshot and miss the
 * comment the other transaction just committed.
 */
class DiscussionStates
{
    public function find(Model $subject): ?DiscussionState
    {
        return DiscussionState::query()
            ->where('commentable_type', $subject->getMorphClass())
            ->where('commentable_id', $subject->getKey())
            ->first();
    }

    public function effectiveSlowMode(Model $subject, SectionSettings $settings, ?DiscussionState $state = null): int
    {
        $state ??= $this->find($subject);

        return $state?->slow_mode_seconds ?? $settings->slowModeSeconds;
    }

    public function isLocked(Model $subject, ?DiscussionState $state = null): bool
    {
        $state ??= $this->find($subject);

        return $state?->locked_at !== null;
    }

    /**
     * Saves a moderator's change. `slow_mode_seconds` null returns to the section default;
     * `locked` true locks (keeping the first lock's time and moderator), false unlocks.
     *
     * @param  array{slow_mode_seconds?: int|null, locked?: bool}  $changes
     */
    public function update(Model $subject, array $changes, int|string $moderatorId): DiscussionState
    {
        return DB::transaction(function () use ($subject, $changes, $moderatorId): DiscussionState {
            $state = $this->lockedState($subject);

            if (array_key_exists('slow_mode_seconds', $changes)) {
                $state->slow_mode_seconds = $changes['slow_mode_seconds'] === null ? null : max(0, (int) $changes['slow_mode_seconds']);
            }

            if (array_key_exists('locked', $changes)) {
                if ($changes['locked'] && $state->locked_at === null) {
                    $state->locked_at = now();
                    $state->locked_by = (int) $moderatorId;
                } elseif (! $changes['locked']) {
                    $state->locked_at = null;
                    $state->locked_by = null;
                }
            }

            $state->save();

            return $state;
        });
    }

    /**
     * Within the posting transaction: refuses when the discussion is locked or the member
     * posted here less than the slow-mode interval ago.
     *
     * @throws ValidationException
     */
    public function assertMayPost(Model $subject, SectionSettings $settings, int $authorId): void
    {
        $state = $this->lockedState($subject);

        if ($state->locked_at !== null) {
            throw ValidationException::withMessages(['body' => __('ratings-and-reviews::messages.discussion_locked')]);
        }

        $remaining = $this->secondsUntilNextPost($subject, $settings, $authorId, $state, locking: true);

        if ($remaining > 0) {
            throw ValidationException::withMessages(['body' => trans_choice('ratings-and-reviews::messages.slow_mode', $remaining, ['seconds' => $remaining])]);
        }
    }

    /** Seconds before this member may post again in this discussion (0: now). */
    public function secondsUntilNextPost(Model $subject, SectionSettings $settings, int $authorId, ?DiscussionState $state = null, bool $locking = false): int
    {
        $interval = $this->effectiveSlowMode($subject, $settings, $state);

        if ($interval <= 0) {
            return 0;
        }

        $last = Comment::query()->withTrashed()
            ->forSubject($subject)
            ->where('user_id', $authorId)
            ->when($locking, fn ($query) => $query->sharedLock())
            ->max('created_at');

        if ($last === null) {
            return 0;
        }

        $next = Carbon::parse($last)->addSeconds($interval);

        return now()->lt($next) ? (int) ceil(now()->diffInSeconds($next, true)) : 0;
    }

    /**
     * The subject's state row, created when missing and locked for the transaction.
     */
    private function lockedState(Model $subject): DiscussionState
    {
        $keys = ['commentable_type' => $subject->getMorphClass(), 'commentable_id' => $subject->getKey()];

        DiscussionState::query()->insertOrIgnore($keys + ['created_at' => now(), 'updated_at' => now()]);

        return DiscussionState::query()->where($keys)->lockForUpdate()->firstOrFail();
    }
}

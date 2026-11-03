<?php

namespace RatingsAndReviews\Services;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RatingsAndReviews\Contracts\RiskSignals;
use RatingsAndReviews\Contracts\Votable;
use RatingsAndReviews\Contracts\VoterIdentity;
use RatingsAndReviews\Events\VoteCast;
use RatingsAndReviews\Models\Vote;
use RatingsAndReviews\Support\VoteContext;
use RatingsAndReviews\Support\VoteDimension;
use RatingsAndReviews\Support\VoteResult;
use RatingsAndReviews\Support\VoterKeys;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

/**
 * Votes on reviews and comments, in one table.
 *
 * One vote per voter, item and dimension: the same value again withdraws it, the other
 * value switches it. Nobody votes on their own content (a guest also not from the network
 * the content was written from), nor on content of a member in a block relation with them
 * (BlockList). A vote is never refused for being suspicious: RiskSignals
 * decides whether it counts, and an uncounted vote is stored with its reason. The counters
 * on the item are recounted from the counted rows under the item's row lock, so a double
 * click or a race cannot drift them.
 */
class VoteService
{
    public function __construct(
        private readonly SectionSettingsRepository $settings,
        private readonly VoterIdentity $identity,
        private readonly RiskSignals $risk,
        private readonly Dispatcher $events,
        private readonly Participation $participation,
    ) {}

    /**
     * @throws ValidationException when the dimension or value is not available here
     * @throws UnauthorizedHttpException when a guest may not vote here
     * @throws AccessDeniedHttpException on the voter's own content
     */
    public function toggle(Request $request, ?Authenticatable $user, Model&Votable $votable, VoteDimension $dimension, int $value): VoteResult
    {
        if (! $votable->acceptsVotes()) {
            throw ValidationException::withMessages(['dimension' => __('ratings-and-reviews::messages.vote_unavailable')]);
        }

        $section = $votable->voteSection();
        $settings = $section !== null ? $this->settings->for($section) : null;
        $columns = $votable->voteCounterColumns()[$dimension->value] ?? [];

        if ($settings === null || ! $settings->enabled || ! $settings->allowsDimension($dimension) || ! array_key_exists($value, $columns) || ! in_array($value, $dimension->allowedValues(), true)) {
            throw ValidationException::withMessages(['dimension' => __('ratings-and-reviews::messages.vote_unavailable')]);
        }

        if ($user === null && ! $settings->guestVotes) {
            throw new UnauthorizedHttpException('', (string) __('ratings-and-reviews::messages.login_to_vote'));
        }

        $userId = $user === null ? null : (int) $user->getAuthIdentifier();
        $networkHash = $this->identity->networkHash($request);

        if ($userId !== null && $userId === $votable->votableAuthorId()) {
            throw new AccessDeniedHttpException((string) __('ratings-and-reviews::messages.vote_own'));
        }

        if ($this->participation->isBlocked($userId, $votable->votableAuthorId())) {
            throw new AccessDeniedHttpException((string) __('ratings-and-reviews::messages.vote_blocked'));
        }

        $authorNetwork = $votable->getAttribute('network_hash');

        if ($userId === null && is_string($authorNetwork) && $networkHash !== null && hash_equals($authorNetwork, $networkHash)) {
            throw new AccessDeniedHttpException((string) __('ratings-and-reviews::messages.vote_own'));
        }

        $voterKey = $this->identity->voterKey($request, $user, $votable);

        if ($voterKey === null) {
            throw ValidationException::withMessages(['dimension' => __('ratings-and-reviews::messages.voter_unknown')]);
        }

        $deviceHash = $this->identity->deviceHash($request);

        [$current, $previous, $counted, $reason, $counts] = DB::transaction(function () use ($votable, $dimension, $value, $userId, $voterKey, $networkHash, $deviceHash): array {
            $votable->newQuery()->whereKey($votable->getKey())->lockForUpdate()->first([$votable->getKeyName()]);

            $existing = $this->votesOn($votable, $dimension)->where('voter_key', $voterKey)->lockForUpdate()->first();
            $previous = $existing?->value;

            if ($existing !== null && $existing->value === $value) {
                $existing->delete();

                return [null, $previous, false, null, $this->recount($votable)];
            }

            if ($existing !== null) {
                $existing->update(['value' => $value]);

                return [$value, $previous, $existing->counted, $existing->uncounted_reason, $this->recount($votable)];
            }

            $verdict = $this->risk->forVote(new VoteContext(
                votable: $votable,
                dimension: $dimension,
                value: $value,
                userId: $userId,
                voterKey: $voterKey,
                networkHash: $networkHash,
                deviceHash: $deviceHash,
                existingVotes: $this->votesOn($votable, $dimension),
            ));

            Vote::query()->insertOrIgnore([
                'votable_type' => $votable->getMorphClass(),
                'votable_id' => $votable->getKey(),
                'dimension' => $dimension->value,
                'value' => $value,
                'user_id' => $userId,
                'voter_key' => $voterKey,
                'network_hash' => $networkHash,
                'device_hash' => $deviceHash,
                'counted' => $verdict->counted,
                'uncounted_reason' => $verdict->counted ? null : $verdict->uncountedReason,
                'meta' => $verdict->meta === null ? null : json_encode($verdict->meta, JSON_THROW_ON_ERROR),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return [$value, null, $verdict->counted, $verdict->counted ? null : $verdict->uncountedReason, $this->recount($votable)];
        }, 3);

        $this->events->dispatch(new VoteCast($votable, $dimension, $current, $previous, $voterKey, $userId, $counted, $reason));

        // Per dimension as the item's resource shows them: only the section's dimensions.
        $shown = array_intersect_key($votable->voteCounterColumns(), array_flip(array_map(fn (VoteDimension $case): string => $case->value, $settings->voteDimensions)));

        return new VoteResult($dimension, $current, $current !== null && $counted, $reason, $counts, VoteResult::byDimension($shown, $counts));
    }

    /**
     * Recounts every counter of the item from its counted vote rows, and writes them with
     * the derived columns. Runs in a transaction under the item's row lock (re-entrant when
     * called from toggle()).
     *
     * @return array<string, int>
     */
    public function recount(Model&Votable $votable): array
    {
        return DB::transaction(function () use ($votable): array {
            $votable->newQueryWithoutScopes()->whereKey($votable->getKey())->lockForUpdate()->first([$votable->getKeyName()]);

            $counters = [];

            foreach ($votable->voteCounterColumns() as $values) {
                foreach ($values as $column) {
                    $counters[$column] = 0;
                }
            }

            $rows = Vote::query()
                ->where('votable_type', $votable->getMorphClass())
                ->where('votable_id', $votable->getKey())
                ->where('counted', true)
                ->toBase()
                ->selectRaw('dimension, value, COUNT(*) as total')
                ->groupBy('dimension', 'value')
                ->get();

            $columns = $votable->voteCounterColumns();

            foreach ($rows as $row) {
                $column = $columns[$row->dimension][(int) $row->value] ?? null;

                if ($column !== null) {
                    $counters[$column] = (int) $row->total;
                }
            }

            $update = $counters + $votable->derivedVoteColumns($counters);
            // Without scopes: a soft-deleted or withheld item is recounted as well (erasure).
            $votable->newQueryWithoutScopes()->whereKey($votable->getKey())->toBase()->update($update);
            $votable->forceFill($update)->syncOriginal();

            return $counters;
        });
    }

    /**
     * The voter's values per item id and dimension: [id => [dimension => value]].
     *
     * @param  list<int>  $ids
     * @return array<int, array<string, int>>
     */
    public function choicesFor(string $votableType, array $ids, ?string $voterKey): array
    {
        if ($ids === [] || $voterKey === null) {
            return [];
        }

        $choices = [];

        Vote::query()
            ->where('votable_type', $votableType)
            ->whereIn('votable_id', $ids)
            ->where('voter_key', $voterKey)
            ->get(['votable_id', 'dimension', 'value'])
            ->each(function (Vote $vote) use (&$choices): void {
                $choices[$vote->votable_id][$vote->dimension->value] = $vote->value;
            });

        return $choices;
    }

    /**
     * The viewer's values on these items, with the viewer's key per item:
     * [id => [dimension => value]]. All items must be of one morph type.
     *
     * @param  iterable<Model>  $items
     * @return array<int, array<string, int>>
     */
    public function viewerChoices(iterable $items, ?VoterKeys $keys): array
    {
        if ($keys === null) {
            return [];
        }

        $choices = [];

        foreach ($keys->group($items) as $key => $group) {
            $choices += $this->choicesFor($group[0]->getMorphClass(), array_map(fn (Model $item): int => (int) $item->getKey(), $group), $key);
        }

        return $choices;
    }

    /**
     * @return Builder<Vote>
     */
    private function votesOn(Model $votable, VoteDimension $dimension): Builder
    {
        return Vote::query()
            ->where('votable_type', $votable->getMorphClass())
            ->where('votable_id', $votable->getKey())
            ->where('dimension', $dimension->value);
    }
}

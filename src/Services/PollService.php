<?php

namespace RatingsAndReviews\Services;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RatingsAndReviews\Contracts\RiskSignals;
use RatingsAndReviews\Contracts\VoterIdentity;
use RatingsAndReviews\Models\Comment;
use RatingsAndReviews\Models\Poll;
use RatingsAndReviews\Models\PollOption;
use RatingsAndReviews\Models\PollVote;
use RatingsAndReviews\Support\PollVoteContext;
use RatingsAndReviews\Support\VoterKeys;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;
use Throwable;

/**
 * Mini polls in comments: a question with 2–4 options, created together with the comment.
 *
 * One vote per poll and voter key (VoterIdentity: `u:<id>` or the guest key), enforced by a
 * unique index. Voting for another option switches the vote; the same option again changes
 * nothing. A vote is never refused for being suspicious: RiskSignals::forPollVote() decides
 * whether it counts, and the option counters are recounted from the counted rows under the
 * poll's row lock. Guests vote where the section allows guest votes.
 */
class PollService
{
    public function __construct(
        private readonly SectionSettingsRepository $settings,
        private readonly VoterIdentity $identity,
        private readonly RiskSignals $risk,
        private readonly Participation $participation,
    ) {}

    public function isEnabled(): bool
    {
        return (bool) config('ratings-and-reviews.polls.enabled', true);
    }

    /**
     * Validates a poll definition from the comment request.
     *
     * @return array{question: string, options: list<string>, closes_at: Carbon|null}
     *
     * @throws ValidationException
     */
    public function definition(mixed $input): array
    {
        $config = (array) config('ratings-and-reviews.polls', []);

        if (! $this->isEnabled()) {
            throw ValidationException::withMessages(['poll' => __('ratings-and-reviews::messages.poll_disabled')]);
        }

        $input = is_array($input) ? $input : [];
        $question = $this->clean($input['question'] ?? null);
        $maxQuestion = max(1, (int) ($config['max_question_length'] ?? 255));

        if ($question === '' || mb_strlen($question) > $maxQuestion) {
            throw ValidationException::withMessages(['poll.question' => __('ratings-and-reviews::messages.poll_question', ['max' => $maxQuestion])]);
        }

        $min = max(2, (int) ($config['min_options'] ?? 2));
        $max = max($min, (int) ($config['max_options'] ?? 4));
        $maxOption = max(1, (int) ($config['max_option_length'] ?? 120));
        $options = [];

        foreach ((array) ($input['options'] ?? []) as $label) {
            $label = $this->clean($label);

            if ($label === '') {
                continue;
            }

            if (mb_strlen($label) > $maxOption) {
                throw ValidationException::withMessages(['poll.options' => __('ratings-and-reviews::messages.poll_option_length', ['max' => $maxOption])]);
            }

            if (in_array(mb_strtolower($label), array_map('mb_strtolower', $options), true)) {
                throw ValidationException::withMessages(['poll.options' => __('ratings-and-reviews::messages.poll_option_duplicate')]);
            }

            $options[] = $label;
        }

        if (count($options) < $min || count($options) > $max) {
            throw ValidationException::withMessages(['poll.options' => __('ratings-and-reviews::messages.poll_options_count', ['min' => $min, 'max' => $max])]);
        }

        $closesAt = null;

        if (($input['closes_at'] ?? null) !== null && $input['closes_at'] !== '') {
            try {
                $closesAt = Carbon::parse((string) $input['closes_at']);
            } catch (Throwable) {
                $closesAt = null;
            }

            $maxDays = $config['max_open_days'] ?? 365;

            if ($closesAt === null || $closesAt->isPast() || ($maxDays !== null && $closesAt->gt(now()->addDays((int) $maxDays)))) {
                throw ValidationException::withMessages(['poll.closes_at' => __('ratings-and-reviews::messages.poll_closes_at', ['days' => (int) $maxDays])]);
            }
        }

        return ['question' => $question, 'options' => $options, 'closes_at' => $closesAt];
    }

    /**
     * Creates the poll of a new comment (inside the comment's transaction).
     *
     * @param  array{question: string, options: list<string>, closes_at: Carbon|null}  $definition
     */
    public function create(Comment $comment, array $definition): Poll
    {
        $poll = Poll::query()->create([
            'comment_id' => $comment->id,
            'question' => $definition['question'],
            'closes_at' => $definition['closes_at'],
        ]);

        foreach ($definition['options'] as $index => $label) {
            PollOption::query()->create(['poll_id' => $poll->id, 'label' => $label, 'sort_order' => $index]);
        }

        return $poll;
    }

    /**
     * @return array{option_id: int, counted: bool, changed: bool}
     *
     * @throws ValidationException
     * @throws UnauthorizedHttpException
     * @throws AccessDeniedHttpException on a poll of a member in a block relation with the voter
     */
    public function vote(Request $request, ?Authenticatable $user, Poll $poll, int $optionId): array
    {
        $comment = $poll->comment;

        if (! $comment instanceof Comment || ! $comment->isPubliclyVisible()) {
            throw ValidationException::withMessages(['option_id' => __('ratings-and-reviews::messages.poll_unavailable')]);
        }

        if ($poll->closes_at !== null && ! $poll->closes_at->isFuture()) {
            throw ValidationException::withMessages(['option_id' => __('ratings-and-reviews::messages.poll_closed')]);
        }

        $settings = $this->settings->for($comment->section);

        if (! $settings->enabled) {
            throw ValidationException::withMessages(['option_id' => __('ratings-and-reviews::messages.poll_unavailable')]);
        }

        if ($user === null && ! $settings->guestVotes) {
            throw new UnauthorizedHttpException('', (string) __('ratings-and-reviews::messages.login_to_vote'));
        }

        if ($user !== null && $this->participation->isBlocked((int) $user->getAuthIdentifier(), $comment->user_id)) {
            throw new AccessDeniedHttpException((string) __('ratings-and-reviews::messages.vote_blocked'));
        }

        $option = PollOption::query()->where('poll_id', $poll->id)->whereKey($optionId)->first();

        if ($option === null) {
            throw ValidationException::withMessages(['option_id' => __('ratings-and-reviews::messages.poll_option_unknown')]);
        }

        $voterKey = $this->identity->voterKey($request, $user, $poll);

        if ($voterKey === null) {
            throw ValidationException::withMessages(['option_id' => __('ratings-and-reviews::messages.voter_unknown')]);
        }

        $userId = $user === null ? null : (int) $user->getAuthIdentifier();
        $networkHash = $this->identity->networkHash($request);
        $deviceHash = $this->identity->deviceHash($request);

        return DB::transaction(function () use ($poll, $option, $userId, $voterKey, $networkHash, $deviceHash): array {
            Poll::query()->whereKey($poll->id)->lockForUpdate()->first(['id']);
            $existing = PollVote::query()->where('poll_id', $poll->id)->where('voter_key', $voterKey)->lockForUpdate()->first();

            if ($existing !== null) {
                $changed = $existing->option_id !== $option->id;

                if ($changed) {
                    $existing->update(['option_id' => $option->id]);
                    $this->recount($poll);
                }

                return ['option_id' => $option->id, 'counted' => $existing->counted, 'changed' => $changed];
            }

            $verdict = $this->risk->forPollVote(new PollVoteContext($poll, $option, $userId, $voterKey, $networkHash, $deviceHash));

            PollVote::query()->insertOrIgnore([
                'poll_id' => $poll->id,
                'option_id' => $option->id,
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

            $this->recount($poll);

            return ['option_id' => $option->id, 'counted' => $verdict->counted, 'changed' => true];
        }, 3);
    }

    /** Recounts every option from the counted vote rows. */
    public function recount(Poll $poll): void
    {
        $totals = PollVote::query()->where('poll_id', $poll->id)->where('counted', true)
            ->toBase()->selectRaw('option_id, COUNT(*) as total')->groupBy('option_id')->pluck('total', 'option_id');

        foreach (PollOption::query()->where('poll_id', $poll->id)->get(['id']) as $option) {
            PollOption::query()->whereKey($option->id)->update(['votes_count' => (int) ($totals[$option->id] ?? 0)]);
        }
    }

    /**
     * The option each poll's voter chose: [poll id => option id].
     *
     * @param  list<int>  $pollIds
     * @return array<int, int>
     */
    public function choicesFor(array $pollIds, ?string $voterKey): array
    {
        if ($pollIds === [] || $voterKey === null) {
            return [];
        }

        return PollVote::query()->whereIn('poll_id', $pollIds)->where('voter_key', $voterKey)
            ->pluck('option_id', 'poll_id')->map(fn (mixed $id): int => (int) $id)->all();
    }

    /**
     * The option the viewer chose per poll, with the viewer's key per poll: [poll id => option id].
     *
     * @param  iterable<Poll>  $polls
     * @return array<int, int>
     */
    public function viewerChoices(iterable $polls, ?VoterKeys $keys): array
    {
        if ($keys === null) {
            return [];
        }

        $choices = [];

        foreach ($keys->group($polls) as $key => $group) {
            $choices += $this->choicesFor(array_map(fn (Poll $poll): int => (int) $poll->id, $group), $key);
        }

        return $choices;
    }

    /**
     * @return array{id: int, question: string, closes_at: string|null, is_closed: bool, total_votes: int, options: list<array{id: int, label: string, votes: int, percentage: float}>, viewer_option_id: int|null}
     */
    public static function present(Poll $poll, ?int $viewerOptionId): array
    {
        $options = $poll->relationLoaded('options') ? $poll->options : $poll->options()->get();
        $total = (int) $options->sum('votes_count');

        return [
            'id' => $poll->id,
            'question' => $poll->question,
            'closes_at' => $poll->closes_at?->toIso8601String(),
            'is_closed' => $poll->closes_at !== null && ! $poll->closes_at->isFuture(),
            'total_votes' => $total,
            'options' => $options->map(fn (PollOption $option): array => [
                'id' => $option->id,
                'label' => $option->label,
                'votes' => $option->votes_count,
                'percentage' => $total === 0 ? 0.0 : round($option->votes_count * 100 / $total, 1),
            ])->values()->all(),
            'viewer_option_id' => $viewerOptionId,
        ];
    }

    private function clean(mixed $value): string
    {
        return is_string($value) ? trim((string) preg_replace('/\s+/u', ' ', strip_tags($value))) : '';
    }
}

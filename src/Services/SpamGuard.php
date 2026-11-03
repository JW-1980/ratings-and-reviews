<?php

namespace RatingsAndReviews\Services;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use RatingsAndReviews\Models\Comment;
use RatingsAndReviews\Models\Review;
use RatingsAndReviews\Support\SpamVerdict;

/**
 * Deterministic spam checks run before a review or comment is stored: honeypot, time trap,
 * blocked and held phrases, number of links, shouting, repeated characters and a duplicate
 * of the author's own recent contribution, compared over the full text.
 *
 * Signals in `spam.hard_signals` refuse the contribution; every other signal holds it for
 * a moderator.
 */
class SpamGuard
{
    /**
     * @param  Model|null  $editing  The review or comment being edited: it is not its own duplicate, and the time trap does not apply.
     */
    public function inspect(?Request $request, string $text, ?int $userId, ?Model $editing = null): SpamVerdict
    {
        $config = (array) config('ratings-and-reviews.spam', []);
        $reasons = [];

        if ($request !== null) {
            $honeypot = (string) ($config['honeypot_field'] ?? 'website');

            if ($honeypot !== '' && $request->filled($honeypot)) {
                $reasons[] = 'honeypot';
            }

            $startedAt = $request->input((string) ($config['started_at_field'] ?? 'form_started_at'));

            if ($editing === null && is_numeric($startedAt) && (time() - (int) $startedAt) < (int) ($config['minimum_seconds'] ?? 2)) {
                $reasons[] = 'submitted_too_fast';
            }
        }

        $lower = mb_strtolower($text);

        if ($this->containsAny($lower, (array) ($config['blocked_phrases'] ?? []))) {
            $reasons[] = 'blocked_phrase';
        }

        if ($this->containsAny($lower, (array) ($config['hold_phrases'] ?? []))) {
            $reasons[] = 'held_phrase';
        }

        if (preg_match_all('~https?://|www\.~i', $text) > (int) ($config['max_links'] ?? 3)) {
            $reasons[] = 'too_many_links';
        }

        $letters = (string) preg_replace('/[^\p{L}]/u', '', $text);

        if (mb_strlen($letters) >= (int) ($config['caps_min_length'] ?? 40) && $letters === mb_strtoupper($letters) && $letters !== mb_strtolower($letters)) {
            $reasons[] = 'all_caps';
        }

        $repeat = max(2, (int) ($config['repeated_characters'] ?? 15));

        if (preg_match('/(.)\1{'.($repeat - 1).',}/u', $text) === 1) {
            $reasons[] = 'repeated_characters';
        }

        if ($this->isDuplicate($text, $userId, $editing)) {
            $reasons[] = 'duplicate_recently';
        }

        $reasons = array_values(array_unique($reasons));
        $hard = array_intersect($reasons, (array) ($config['hard_signals'] ?? []));

        return new SpamVerdict(
            refuse: $hard !== [],
            hold: $reasons !== [] && $hard === [],
            reasons: $reasons,
        );
    }

    /**
     * Whether the same user posted exactly this text (case and whitespace ignored) as a
     * review or comment within `spam.duplicate_minutes`. The whole text is compared, not a
     * prefix, so a long review that only shares its opening is not a duplicate.
     */
    public function isDuplicate(string $text, ?int $userId, ?Model $editing = null): bool
    {
        $normalised = $this->normalise($text);

        if ($userId === null || mb_strlen($normalised) < (int) config('ratings-and-reviews.spam.duplicate_min_length', 20)) {
            return false;
        }

        $since = now()->subMinutes(max(1, (int) config('ratings-and-reviews.spam.duplicate_minutes', 10)));

        foreach ([Comment::class, Review::class] as $model) {
            $query = $model::query()
                ->withTrashed()
                ->where('user_id', $userId)
                ->where('created_at', '>=', $since)
                ->whereRaw('LOWER(body_text) = ?', [$normalised]);

            if ($editing instanceof $model) {
                $query->whereKeyNot($editing->getKey());
            }

            if ($query->exists()) {
                return true;
            }
        }

        return false;
    }

    private function normalise(string $text): string
    {
        return mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $text)));
    }

    /**
     * @param  array<mixed>  $phrases
     */
    private function containsAny(string $lowerText, array $phrases): bool
    {
        foreach ($phrases as $phrase) {
            if (is_string($phrase) && $phrase !== '' && str_contains($lowerText, mb_strtolower($phrase))) {
                return true;
            }
        }

        return false;
    }
}

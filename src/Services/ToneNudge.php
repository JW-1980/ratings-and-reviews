<?php

namespace RatingsAndReviews\Services;

use RatingsAndReviews\Support\ToneSuggestion;
use RatingsAndReviews\Support\WordMatcher;

/**
 * The rephrase nudge, without AI: the text is matched against the configured nl and en word
 * lists (`tone.words`), as whole words. A match returns a suggestion ("would you like to put
 * this differently?"); posting with `confirm_tone=true` always goes through.
 */
class ToneNudge
{
    public function isEnabled(): bool
    {
        return (bool) config('ratings-and-reviews.tone.enabled', true);
    }

    public function check(string $text): ?ToneSuggestion
    {
        if (! $this->isEnabled()) {
            return null;
        }

        $matches = WordMatcher::matches($text, $this->words());

        return $matches === [] ? null : new ToneSuggestion($matches);
    }

    /**
     * Every configured word over all languages, in configuration order.
     *
     * @return list<string>
     */
    public function words(): array
    {
        $words = [];

        foreach ((array) config('ratings-and-reviews.tone.words', []) as $list) {
            foreach ((array) $list as $word) {
                if (is_string($word) && trim($word) !== '') {
                    $words[] = $word;
                }
            }
        }

        return $words;
    }
}

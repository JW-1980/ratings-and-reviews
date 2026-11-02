<?php

namespace RatingsAndReviews\Support;

/**
 * The rephrase nudge: the words that suggested a hostile tone. It never blocks a post that
 * is confirmed.
 */
final readonly class ToneSuggestion
{
    /**
     * @param  list<string>  $matches
     */
    public function __construct(public array $matches) {}

    public function message(): string
    {
        return (string) __('ratings-and-reviews::messages.tone_nudge');
    }

    /**
     * @return array{message: string, matches: list<string>}
     */
    public function toArray(): array
    {
        return ['message' => $this->message(), 'matches' => $this->matches];
    }
}

<?php

namespace RatingsAndReviews\Support;

/**
 * Extractive excerpts: the first sentence of a plain text, cut at a word boundary.
 */
final class Excerpt
{
    /**
     * The text up to and including the first `.`, `!`, `?` or `…` that is followed by
     * whitespace or the end; the whole text when there is none. Longer than `$max`
     * characters: cut at the last space before the limit (when that keeps at least half)
     * and ended with "…".
     */
    public static function firstSentence(string $text, int $max): string
    {
        $text = trim((string) preg_replace('/\s+/u', ' ', $text));
        $max = max(1, $max);

        if (preg_match('/^(.+?[.!?…])(?=\s|$)/u', $text, $match) === 1) {
            $text = $match[1];
        }

        return self::cut($text, $max);
    }

    public static function cut(string $text, int $max): string
    {
        if (mb_strlen($text) <= $max) {
            return $text;
        }

        $cut = mb_substr($text, 0, $max);
        $space = mb_strrpos($cut, ' ');

        if ($space !== false && $space >= intdiv($max, 2)) {
            $cut = mb_substr($cut, 0, $space);
        }

        return rtrim($cut, ' ,;:-').'…';
    }
}

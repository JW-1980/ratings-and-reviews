<?php

namespace RatingsAndReviews\Support;

/**
 * Whole-word matching shared by the quality meter and the rephrase nudge, specified so a
 * JavaScript mirror gives the same answer: the text is whitespace-collapsed, trimmed and
 * lower-cased; a word or phrase matches when it is not directly preceded or followed by a
 * letter or digit (`(?<![\p{L}\p{N}])phrase(?![\p{L}\p{N}])`, flag `u`).
 */
final class WordMatcher
{
    public static function normalize(string $text): string
    {
        return mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $text)));
    }

    public static function contains(string $normalizedText, string $phrase): bool
    {
        $phrase = self::normalize($phrase);

        if ($phrase === '' || $normalizedText === '') {
            return false;
        }

        return preg_match('/(?<![\p{L}\p{N}])'.preg_quote($phrase, '/').'(?![\p{L}\p{N}])/u', $normalizedText) === 1;
    }

    /**
     * The phrases (normalised, unique, in list order) found in the text.
     *
     * @param  iterable<mixed>  $phrases
     * @return list<string>
     */
    public static function matches(string $text, iterable $phrases): array
    {
        $normalized = self::normalize($text);
        $found = [];

        foreach ($phrases as $phrase) {
            if (! is_string($phrase)) {
                continue;
            }

            $phrase = self::normalize($phrase);

            if (! in_array($phrase, $found, true) && self::contains($normalized, $phrase)) {
                $found[] = $phrase;
            }
        }

        return $found;
    }
}

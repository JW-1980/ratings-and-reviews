<?php

namespace RatingsAndReviews\Services;

use Normalizer;

/**
 * Builds search snippets from plain text: a window around the first hit, HTML-escaped,
 * with every hit wrapped in `<mark>`. Escaping happens per segment after the hits are found,
 * so user text can never inject markup. Matching mirrors MySQL's utf8mb4_unicode_ci closely
 * enough for highlighting: case-insensitive and, when ext-intl is present, accent-insensitive
 * per character (so "creme" marks "crème"). Terms are prefix matches at a word start, as in
 * the boolean-mode query (`term*`); phrases match their words in order.
 */
class SearchHighlighter
{
    /**
     * @param  list<string>  $terms
     * @param  list<string>  $phrases
     */
    public function snippet(string $text, array $terms, array $phrases, int $length): string
    {
        $chars = mb_str_split($text);
        $count = count($chars);
        $length = max(20, $length);
        $hits = $this->hits($chars, $terms, $phrases);

        $start = 0;

        if ($hits !== [] && $count > $length) {
            $start = max(0, $hits[0][0] - intdiv($length, 3));

            if ($start > 0) {
                // Begin at a word: after the next space within reach.
                for ($i = $start; $i < min($count, $start + 20); $i++) {
                    if ($chars[$i] === ' ') {
                        $start = $i + 1;
                        break;
                    }
                }
            }
        }

        $end = min($count, $start + $length);

        if ($end < $count) {
            for ($i = $end; $i > $start + intdiv($length, 2); $i--) {
                if ($chars[$i] === ' ') {
                    $end = $i;
                    break;
                }
            }
        }

        $html = $start > 0 ? '…' : '';
        $cursor = $start;

        foreach ($hits as [$hitStart, $hitEnd]) {
            if ($hitStart < $cursor || $hitEnd > $end) {
                continue;
            }

            $html .= $this->escape($chars, $cursor, $hitStart).'<mark>'.$this->escape($chars, $hitStart, $hitEnd).'</mark>';
            $cursor = $hitEnd;
        }

        return $html.$this->escape($chars, $cursor, $end).($end < $count ? '…' : '');
    }

    /** The whole text escaped, with every hit marked. */
    public function highlight(string $text, array $terms, array $phrases): string
    {
        return $this->snippet($text, $terms, $phrases, max(20, mb_strlen($text)));
    }

    /**
     * Non-overlapping hits as [start, end) character offsets, in text order.
     *
     * @param  list<string>  $chars
     * @param  list<string>  $terms
     * @param  list<string>  $phrases
     * @return list<array{0: int, 1: int}>
     */
    private function hits(array $chars, array $terms, array $phrases): array
    {
        $folded = implode('', array_map(fn (string $char): string => $this->fold($char), $chars));
        $patterns = [];

        foreach ($phrases as $phrase) {
            $words = array_map(fn (string $word): string => preg_quote($this->foldString($word), '/'), preg_split('/\s+/u', trim($phrase)) ?: []);
            $patterns[] = '(?<![\p{L}\p{N}])'.implode('\s+', $words).'(?![\p{L}\p{N}])';
        }

        foreach ($terms as $term) {
            $patterns[] = '(?<![\p{L}\p{N}])'.preg_quote($this->foldString($term), '/').'[\p{L}\p{N}]*';
        }

        if ($patterns === [] || preg_match_all('/'.implode('|', $patterns).'/u', $folded, $matches, PREG_OFFSET_CAPTURE) === false) {
            return [];
        }

        $hits = [];

        foreach ($matches[0] as [$match, $byteOffset]) {
            if ($match === '') {
                continue;
            }

            $start = mb_strlen(substr($folded, 0, $byteOffset));
            $hits[] = [$start, $start + mb_strlen($match)];
        }

        return $hits;
    }

    /**
     * @param  list<string>  $chars
     */
    private function escape(array $chars, int $from, int $to): string
    {
        return $to <= $from ? '' : htmlspecialchars(implode('', array_slice($chars, $from, $to - $from)), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    private function foldString(string $value): string
    {
        return implode('', array_map(fn (string $char): string => $this->fold($char), mb_str_split($value)));
    }

    /** One character, lower-cased and without accents; always exactly one character. */
    private function fold(string $char): string
    {
        $lower = mb_strtolower($char);

        if (class_exists(Normalizer::class) && strlen($lower) > 1) {
            $decomposed = Normalizer::normalize($lower, Normalizer::FORM_D);
            $base = is_string($decomposed) ? (string) preg_replace('/\p{Mn}+/u', '', $decomposed) : $lower;

            if (mb_strlen($base) === 1) {
                return $base;
            }
        }

        return mb_strlen($lower) === 1 ? $lower : $char;
    }
}

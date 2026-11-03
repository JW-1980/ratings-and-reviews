<?php

namespace RatingsAndReviews\Services;

use RatingsAndReviews\Support\QualityAssessment;
use RatingsAndReviews\Support\WordMatcher;

/**
 * The review quality meter, without AI: configurable rules (`quality.rules`) award points
 * for length, pros, cons, describing use, rating the topics and being concrete (a digit or
 * a unit word). Score = earned / applicable points × 100, rounded half up. A rule that
 * cannot apply (no topics to rate, pros and cons switched off) counts for neither.
 *
 * rules() is the exact data the JavaScript mirror computes with; assess() is the reference.
 *
 * Rule types:
 * - `length`  steps [[min_code_points, points], …]: the points of the highest step reached.
 * - `pros`, `cons`  points when at least one is given (applies only with extended fields).
 * - `words`  points when the text contains one of `words`.
 * - `topics`  points × rated / available topics, rounded down (applies only when the subject has topics).
 * - `concrete`  points when the text contains a decimal digit (\p{Nd}) or one of `units`.
 */
class ReviewQualityMeter
{
    public const VERSION = 1;

    /**
     * @param  array{text?: string, pros?: int, cons?: int, topics_rated?: int, topics_available?: int, extended_fields?: bool}  $input
     */
    public function assess(array $input): QualityAssessment
    {
        $text = WordMatcher::normalize((string) ($input['text'] ?? ''));
        $length = mb_strlen($text);
        $extended = (bool) ($input['extended_fields'] ?? true);
        $topicsAvailable = max(0, (int) ($input['topics_available'] ?? 0));
        $topicsRated = max(0, min($topicsAvailable, (int) ($input['topics_rated'] ?? 0)));

        $earnedTotal = 0;
        $availableTotal = 0;
        $hints = [];
        $perRule = [];

        foreach ($this->configuredRules() as $rule) {
            $key = $rule['key'];
            $available = $this->maxPoints($rule);
            $earned = 0;

            switch ($rule['type']) {
                case 'length':
                    foreach ($rule['steps'] as [$min, $points]) {
                        if ($length >= $min) {
                            $earned = max($earned, $points);
                        }
                    }
                    break;
                case 'pros':
                case 'cons':
                    if (! $extended) {
                        continue 2;
                    }
                    $earned = (int) ($input[$rule['type']] ?? 0) > 0 ? $available : 0;
                    break;
                case 'words':
                    $earned = WordMatcher::matches($text, $rule['words']) !== [] ? $available : 0;
                    break;
                case 'topics':
                    if ($topicsAvailable === 0) {
                        continue 2;
                    }
                    $earned = intdiv($available * $topicsRated, $topicsAvailable);
                    break;
                case 'concrete':
                    $earned = (preg_match('/\p{Nd}/u', $text) === 1 || WordMatcher::matches($text, $rule['units']) !== []) ? $available : 0;
                    break;
                default:
                    continue 2;
            }

            $earnedTotal += $earned;
            $availableTotal += $available;
            $perRule[$key] = ['earned' => $earned, 'available' => $available];

            if ($earned < $available) {
                $hints[] = $key;
            }
        }

        $score = $availableTotal === 0 ? 0 : (int) floor($earnedTotal * 100 / $availableTotal + 0.5);

        return new QualityAssessment($score, $this->level($score), $hints, $perRule);
    }

    /**
     * The rules as served to the browser, with translated hints and level labels.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $levels = $this->levels();

        return [
            'version' => self::VERSION,
            'text' => ['normalize' => 'collapse_whitespace_trim_lowercase', 'length_unit' => 'code_points', 'word_boundary' => '(?<![\p{L}\p{N}])…(?![\p{L}\p{N}])'],
            'score' => 'round_half_up(earned * 100 / applicable)',
            'rules' => array_map(fn (array $rule): array => $rule + [
                'max_points' => $this->maxPoints($rule),
                'hint' => (string) __('ratings-and-reviews::messages.quality_hints.'.$rule['key']),
            ], $this->configuredRules()),
            'levels' => array_map(fn (string $level, int $min): array => [
                'level' => $level,
                'min' => $min,
                'label' => (string) __('ratings-and-reviews::messages.quality_levels.'.$level),
            ], array_keys($levels), array_values($levels)),
        ];
    }

    public function level(int $score): string
    {
        $current = 'weak';

        foreach (array_slice($this->levels(), 1, null, true) as $level => $min) {
            if ($score >= $min) {
                $current = $level;
            }
        }

        return $current;
    }

    /**
     * @return array<string, int> level => minimum score, ascending
     */
    private function levels(): array
    {
        $configured = (array) config('ratings-and-reviews.quality.levels', []);
        $levels = ['weak' => 0];

        foreach (['fair', 'good', 'strong'] as $level) {
            $levels[$level] = max(0, min(100, (int) ($configured[$level] ?? 100)));
        }

        asort($levels);

        return $levels;
    }

    /**
     * The configured rules, validated: unknown types and malformed entries are dropped.
     *
     * @return list<array{key: string, type: string, points?: int, steps?: list<array{0: int, 1: int}>, words?: list<string>, units?: list<string>}>
     */
    public function configuredRules(): array
    {
        $rules = [];

        foreach ((array) config('ratings-and-reviews.quality.rules', []) as $rule) {
            if (! is_array($rule) || ! is_string($rule['key'] ?? null) || ! is_string($rule['type'] ?? null)) {
                continue;
            }

            $clean = ['key' => $rule['key'], 'type' => $rule['type']];

            switch ($rule['type']) {
                case 'length':
                    $steps = [];
                    foreach ((array) ($rule['steps'] ?? []) as $step) {
                        if (is_array($step) && count($step) === 2) {
                            $steps[] = [max(0, (int) $step[0]), max(0, (int) $step[1])];
                        }
                    }
                    usort($steps, fn (array $a, array $b): int => $a[0] <=> $b[0]);
                    $clean['steps'] = $steps;
                    break;
                case 'pros':
                case 'cons':
                case 'topics':
                    $clean['points'] = max(0, (int) ($rule['points'] ?? 0));
                    break;
                case 'words':
                    $clean['points'] = max(0, (int) ($rule['points'] ?? 0));
                    $clean['words'] = $this->wordList($rule['words'] ?? []);
                    break;
                case 'concrete':
                    $clean['points'] = max(0, (int) ($rule['points'] ?? 0));
                    $clean['units'] = $this->wordList($rule['units'] ?? []);
                    break;
                default:
                    continue 2;
            }

            $rules[] = $clean;
        }

        return $rules;
    }

    /**
     * @param  array<string, mixed>  $rule
     */
    private function maxPoints(array $rule): int
    {
        if ($rule['type'] === 'length') {
            return array_reduce($rule['steps'] ?? [], fn (int $max, array $step): int => max($max, $step[1]), 0);
        }

        return (int) ($rule['points'] ?? 0);
    }

    /**
     * @return list<string>
     */
    private function wordList(mixed $words): array
    {
        return array_values(array_unique(array_filter(
            array_map(fn (mixed $word): string => is_string($word) ? WordMatcher::normalize($word) : '', (array) $words),
            fn (string $word): bool => $word !== '',
        )));
    }
}

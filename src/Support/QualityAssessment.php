<?php

namespace RatingsAndReviews\Support;

/**
 * The outcome of the ReviewQualityMeter: a score of 0–100, a level label and the hints
 * (rule keys whose points were not, or not fully, earned).
 */
final readonly class QualityAssessment
{
    /**
     * @param  list<string>  $hints  Rule keys, in rule order.
     * @param  array<string, array{earned: int, available: int}>  $rules
     */
    public function __construct(
        public int $score,
        public string $level,
        public array $hints,
        public array $rules,
    ) {}

    /**
     * @return array{score: int, level: string, level_label: string, hints: list<array{key: string, message: string}>}
     */
    public function toArray(): array
    {
        return [
            'score' => $this->score,
            'level' => $this->level,
            'level_label' => (string) __('ratings-and-reviews::messages.quality_levels.'.$this->level),
            'hints' => array_map(fn (string $key): array => [
                'key' => $key,
                'message' => (string) __('ratings-and-reviews::messages.quality_hints.'.$key),
            ], $this->hints),
        ];
    }
}

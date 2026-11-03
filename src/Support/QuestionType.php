<?php

namespace RatingsAndReviews\Support;

/**
 * The kinds of review question: a 1 … `review_questions.scale_max` scale with words at both
 * ends, one option out of a list, or several options out of a list.
 */
enum QuestionType: string
{
    case Scale = 'scale';
    case Choice = 'choice';
    case Multi = 'multi';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(fn (self $case): string => $case->value, self::cases());
    }

    public function hasOptions(): bool
    {
        return $this !== self::Scale;
    }
}

<?php

namespace RatingsAndReviews\Support;

/**
 * "Compared with…": how the reviewed subject compares with the alternative the reviewer named.
 */
enum Comparison: string
{
    case Better = 'better';
    case Worse = 'worse';
    case Same = 'same';

    public function label(): string
    {
        return (string) __('ratings-and-reviews::enums.comparison.'.$this->value);
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(fn (self $comparison): string => $comparison->value, self::cases());
    }
}

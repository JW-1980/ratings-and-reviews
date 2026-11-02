<?php

namespace RatingsAndReviews\Support;

enum ReviewSort: string
{
    case Helpful = 'helpful';
    case Newest = 'newest';
    case Highest = 'highest';
    case Lowest = 'lowest';

    public function label(): string
    {
        return (string) __('ratings-and-reviews::enums.review_sort.'.$this->value);
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(fn (self $sort): string => $sort->value, self::cases());
    }
}

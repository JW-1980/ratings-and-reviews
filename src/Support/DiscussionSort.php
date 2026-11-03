<?php

namespace RatingsAndReviews\Support;

enum DiscussionSort: string
{
    /** Wilson lower bound of the helpfulness votes (the stored `helpful_score`). */
    case Helpful = 'helpful';
    case Newest = 'newest';
    case Oldest = 'oldest';
    /** `controversy_score`: many agree and many disagree votes first. */
    case Controversial = 'controversial';

    public function label(): string
    {
        return (string) __('ratings-and-reviews::enums.discussion_sort.'.$this->value);
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(fn (self $sort): string => $sort->value, self::cases());
    }
}

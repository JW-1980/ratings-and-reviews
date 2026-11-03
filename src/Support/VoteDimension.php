<?php

namespace RatingsAndReviews\Support;

/**
 * One axis a reader can vote on. Each dimension holds at most one vote per voter and item;
 * a second vote with the other value switches, the same value again withdraws.
 */
enum VoteDimension: string
{
    case Agreement = 'agreement';
    case Helpfulness = 'helpfulness';
    case NeedsSource = 'needs_source';

    public function label(): string
    {
        return (string) __('ratings-and-reviews::enums.vote_dimension.'.$this->value);
    }

    /**
     * The values a vote on this dimension may have. "Needs a source" is a request, so it
     * has no negative.
     *
     * @return list<int>
     */
    public function allowedValues(): array
    {
        return match ($this) {
            self::NeedsSource => [1],
            default => [1, -1],
        };
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(fn (self $dimension): string => $dimension->value, self::cases());
    }
}

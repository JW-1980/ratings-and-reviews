<?php

namespace RatingsAndReviews\Support;

/**
 * Moderation status of a review or a comment.
 */
enum ContentStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Spam = 'spam';

    public function label(): string
    {
        return (string) __('ratings-and-reviews::enums.content_status.'.$this->value);
    }

    /** Whether a moderator explicitly refused this content (it never auto-returns to approved). */
    public function isRefused(): bool
    {
        return $this === self::Rejected || $this === self::Spam;
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(fn (self $status): string => $status->value, self::cases());
    }
}

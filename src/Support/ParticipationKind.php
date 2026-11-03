<?php

namespace RatingsAndReviews\Support;

/**
 * The kinds of contribution KindAwareParticipationRules is asked about.
 */
final class ParticipationKind
{
    public const REVIEW = 'review';

    /** A comment or a reply to a comment on the subject itself. */
    public const COMMENT = 'comment';

    /** A comment on one of the subject's reviews (reviews.replies), asked of the reviewed subject. */
    public const REVIEW_REPLY = 'review_reply';

    /** A topic rating without a review (standalone_topic_ratings). */
    public const TOPIC_RATING = 'topic_rating';

    /** The author's follow-up on their review. */
    public const FOLLOW_UP = 'follow_up';

    /**
     * @return list<string>
     */
    public static function all(): array
    {
        return [self::REVIEW, self::COMMENT, self::REVIEW_REPLY, self::TOPIC_RATING, self::FOLLOW_UP];
    }
}

<?php

namespace RatingsAndReviews\Contracts;

use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Optional, on a Commentable and/or Reviewable subject: who may take part and whether a
 * member's contributions skip or always get pre-moderation. Without it everyone who can see
 * the subject may write, and the SpamGuard, RiskSignals and section settings decide holds.
 * The same answers apply to the subject's comments, reviews, follow-ups and topic ratings.
 */
interface ParticipationRules
{
    /**
     * Whether this member may write here (comment, reply, review, topic rating), for example
     * only members of a private group. False answers 403; reading is governed by
     * commentsVisibleTo()/reviewsVisibleTo().
     */
    public function canParticipate(Authenticatable $user): bool;

    /**
     * Pre-moderation of this author's new and edited contributions: true always holds them for
     * a moderator (status pending), false publishes them at once (only a hard SpamGuard refusal
     * still refuses; the SpamGuard hold, a RiskSignals hold, `review_requires_approval` and
     * `photos_require_approval` are skipped), null leaves it to those checks.
     */
    public function holdsContributionFrom(Authenticatable $author): ?bool;
}

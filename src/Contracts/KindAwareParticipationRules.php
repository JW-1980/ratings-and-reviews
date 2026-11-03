<?php

namespace RatingsAndReviews\Contracts;

use Illuminate\Contracts\Auth\Authenticatable;
use RatingsAndReviews\Support\ParticipationKind;

/**
 * Optional, on a Commentable and/or Reviewable subject: who may write, per kind of
 * contribution (ParticipationKind: `review`, `comment`, `review_reply`, `topic_rating`,
 * `follow_up`). When the subject implements it, it decides instead of
 * ParticipationRules::canParticipate(); holdsContributionFrom() stays with
 * ParticipationRules. Example: on a branch, only customers write reviews, but the branch's
 * staff may reply to them (`review_reply`).
 *
 * `review_reply` is asked of the reviewed subject for a reply on one of its reviews (the
 * package's Review passes it on); `comment` covers comments and replies to comments on the
 * subject itself. Unknown kinds may be added later: answer false only for kinds you refuse.
 */
interface KindAwareParticipationRules
{
    /**
     * Whether this member may write a contribution of this kind here. False answers 403
     * (and `meta.can_participate` false for the kind of the list).
     *
     * @param  ParticipationKind::*  $kind
     */
    public function canParticipateIn(Authenticatable $user, string $kind): bool;
}

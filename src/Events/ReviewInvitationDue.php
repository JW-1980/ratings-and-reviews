<?php

namespace RatingsAndReviews\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use RatingsAndReviews\Models\ReviewInvitation;

/** A review invitation is due; the host sends it. (Fired by the review-invitations feature.) */
final readonly class ReviewInvitationDue implements ShouldDispatchAfterCommit
{
    public function __construct(public ReviewInvitation $invitation) {}
}

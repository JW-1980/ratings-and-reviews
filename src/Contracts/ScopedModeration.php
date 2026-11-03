<?php

namespace RatingsAndReviews\Contracts;

use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Optional, on a Commentable and/or Reviewable subject: moderators scoped to this subject
 * (a branch, chain or brand moderator). Combined with the AbilityResolver: a user moderates
 * an item when `rr.moderate` allows it OR the item's subject answers true here. Applies to
 * the item endpoints (edit, delete, pin, lock and slow mode, `PATCH admin/moderation/{kind}/{id}`,
 * exemptions from trust levels, slow mode and locks); the moderation and report inboxes
 * (`admin/moderation`, `admin/reports`) stay with the AbilityResolver.
 */
interface ScopedModeration
{
    public function canModerate(Authenticatable $user): bool;
}

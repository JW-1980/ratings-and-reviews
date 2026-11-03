<?php

namespace RatingsAndReviews\Contracts;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

/**
 * Who is voting: `u:<id>` for members, `g:<hmac>` for guests, plus hashed network and
 * device signals. No raw IP address or token is ever stored.
 */
interface VoterIdentity
{
    /**
     * The voter key, or null when this guest cannot be identified. `$votable` is the item
     * voted on (a Review, Comment or Poll), so a host can decide the voice per item; it is
     * also passed when the package reads the viewer's own votes on a page (once per item).
     * Null only for callers outside the package that ask without an item.
     */
    public function voterKey(Request $request, ?Authenticatable $user, ?Model $votable = null): ?string;

    /** Hash of the client's network (IPv4 address or IPv6 prefix). */
    public function networkHash(Request $request): ?string;

    /** Hash of the client's device, when the host can tell (device-id). */
    public function deviceHash(Request $request): ?string;

    /** Hash of the full client IP. */
    public function ipHash(Request $request): ?string;
}

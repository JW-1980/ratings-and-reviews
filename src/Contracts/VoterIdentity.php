<?php

namespace RatingsAndReviews\Contracts;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;

/**
 * Who is voting: `u:<id>` for members, `g:<hmac>` for guests, plus hashed network and
 * device signals. No raw IP address or token is ever stored.
 */
interface VoterIdentity
{
    /** The voter key, or null when this guest cannot be identified. */
    public function voterKey(Request $request, ?Authenticatable $user): ?string;

    /** Hash of the client's network (IPv4 address or IPv6 prefix). */
    public function networkHash(Request $request): ?string;

    /** Hash of the client's device, when the host can tell (device-id). */
    public function deviceHash(Request $request): ?string;

    /** Hash of the full client IP. */
    public function ipHash(Request $request): ?string;
}

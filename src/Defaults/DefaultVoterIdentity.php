<?php

namespace RatingsAndReviews\Defaults;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;
use RatingsAndReviews\Contracts\VoterIdentity;

/**
 * Members vote as `u:<id>`. A guest is keyed on an HMAC of their network (IPv4 address or
 * IPv6 prefix) and user agent, so no raw address is stored; hosts that know the device
 * (jw-1980/device-id) bind their own implementation.
 */
class DefaultVoterIdentity implements VoterIdentity
{
    public function voterKey(Request $request, ?Authenticatable $user): ?string
    {
        if ($user !== null) {
            return 'u:'.$user->getAuthIdentifier();
        }

        $network = $this->network($request->ip());

        if ($network === null) {
            return null;
        }

        return 'g:'.$this->hash('guest|'.$network.'|'.(string) $request->userAgent());
    }

    public function networkHash(Request $request): ?string
    {
        $network = $this->network($request->ip());

        return $network === null ? null : $this->hash('network|'.$network);
    }

    public function deviceHash(Request $request): ?string
    {
        return null;
    }

    public function ipHash(Request $request): ?string
    {
        $ip = $request->ip();

        return $ip === null || $ip === '' ? null : $this->hash('ip|'.$ip);
    }

    /**
     * The IPv4 address, or the IPv6 prefix of `identity.ipv6_prefix_bits` bits, because one
     * subscriber usually holds a whole IPv6 prefix.
     */
    public function network(?string $ip): ?string
    {
        if ($ip === null || filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return null;
        }

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            return $ip;
        }

        $bits = max(16, min(128, (int) config('ratings-and-reviews.identity.ipv6_prefix_bits', 48)));
        $binary = (string) inet_pton($ip);
        $bytes = intdiv($bits, 8);
        $prefix = substr($binary, 0, $bytes);
        $remainder = $bits % 8;

        if ($remainder > 0) {
            $prefix .= chr(ord($binary[$bytes]) & (0xFF << (8 - $remainder)) & 0xFF);
        }

        return bin2hex($prefix).'/'.$bits;
    }

    private function hash(string $value): string
    {
        $key = (string) (config('ratings-and-reviews.identity.hash_key') ?? config('app.key'));

        return hash_hmac('sha256', $value, 'rr|'.$key);
    }
}

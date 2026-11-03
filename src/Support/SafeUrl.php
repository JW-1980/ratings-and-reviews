<?php

namespace RatingsAndReviews\Support;

/**
 * Host-supplied URLs (author profiles, official replies, product cards) end up in `href`
 * and `src` attributes. Only http(s) and relative URLs are allowed: a stored
 * `javascript:`/`data:` URL from a host field must never become a clickable script.
 */
final class SafeUrl
{
    public static function sanitize(?string $url): ?string
    {
        if ($url === null) {
            return null;
        }

        $url = trim($url);

        if ($url === '' || str_starts_with($url, '//')) {
            return null;
        }

        // An explicit scheme is allowed only for http(s); a relative URL has none.
        if (preg_match('/^[a-z][a-z0-9+.-]*:/i', $url) === 1) {
            return preg_match('/^https?:/i', $url) === 1 ? $url : null;
        }

        return $url;
    }
}

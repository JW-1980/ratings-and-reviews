<?php

namespace RatingsAndReviews\Services;

use RatingsAndReviews\Models\Sticker;
use RatingsAndReviews\Models\Upload;

/**
 * Decides which image sources may appear in user HTML: the author's own uploads and the
 * active stickers. Anything else (remote images, other members' uploads, switched-off
 * stickers) would let an author track readers or embed foreign content.
 */
class MediaSources
{
    /**
     * @param  list<string>  $candidates  The `src` values found in the input.
     * @return array<string, string|null> Allowed src => the rr_uploads path it refers to (null for a sticker).
     */
    public function allowed(array $candidates, ?int $authorId, bool $uploads, bool $stickers): array
    {
        $candidates = array_values(array_unique(array_filter($candidates, fn (string $src): bool => $src !== '')));

        if ($candidates === []) {
            return [];
        }

        $allowed = [];

        if ($uploads && $authorId !== null) {
            $paths = $this->pathsUnder($candidates, (string) config('ratings-and-reviews.uploads.url_prefix', '/storage/'));

            if ($paths !== []) {
                $owned = Upload::query()->where('user_id', $authorId)->whereIn('path', array_values($paths))->pluck('path')->all();

                foreach ($paths as $src => $path) {
                    if (in_array($path, $owned, true)) {
                        $allowed[$src] = $path;
                    }
                }
            }
        }

        if ($stickers) {
            $paths = $this->pathsUnder($candidates, (string) config('ratings-and-reviews.stickers.url_prefix', '/storage/'));

            if ($paths !== []) {
                $active = Sticker::query()->active()->whereIn('path', array_values($paths))->pluck('path')->all();

                foreach ($paths as $src => $path) {
                    if (! array_key_exists($src, $allowed) && in_array($path, $active, true)) {
                        $allowed[$src] = null;
                    }
                }
            }
        }

        return $allowed;
    }

    /**
     * @param  list<string>  $candidates
     * @return array<string, string> src => path below the prefix
     */
    private function pathsUnder(array $candidates, string $prefix): array
    {
        $prefix = rtrim($prefix, '/').'/';
        $paths = [];

        foreach ($candidates as $src) {
            if (! str_starts_with($src, $prefix)) {
                continue;
            }

            $path = substr($src, strlen($prefix));

            // No traversal, query strings, fragments or encoded tricks: a stored path is plain.
            if ($path !== '' && preg_match('#^[A-Za-z0-9_\-][A-Za-z0-9_\-./]*$#', $path) === 1 && ! str_contains($path, '..')) {
                $paths[$src] = $path;
            }
        }

        return $paths;
    }
}

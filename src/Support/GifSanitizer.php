<?php

namespace RatingsAndReviews\Support;

/**
 * Rewrites a GIF block by block so only what is needed to show it remains: the header, the
 * colour tables, the graphic control blocks (frame timing and transparency), the frames
 * and the NETSCAPE/ANIMEXTS loop count. Comment, plain-text and other application
 * extensions (XMP and other metadata, hidden text) and every byte after the trailer are
 * dropped. Animation is kept, which GD cannot do (it reads and writes only the first
 * frame). The pixel data is copied untouched, not decoded.
 *
 * A malformed or truncated file gives null: the caller refuses it.
 */
final class GifSanitizer
{
    private const KEPT_APPLICATIONS = ['NETSCAPE2.0', 'ANIMEXTS1.0'];

    /** @return array{bytes: string, frames: int}|null */
    public static function sanitize(string $gif): ?array
    {
        $length = strlen($gif);

        if ($length < 14 || ! in_array(substr($gif, 0, 6), ['GIF87a', 'GIF89a'], true)) {
            return null;
        }

        $out = substr($gif, 0, 13);
        $position = 13 + self::tableSize(ord($gif[10]));
        $out .= substr($gif, 13, $position - 13);
        $frames = 0;

        while ($position < $length) {
            $introducer = ord($gif[$position]);

            if ($introducer === 0x3B) {
                return $frames > 0 && $position + 1 <= $length ? ['bytes' => $out."\x3B", 'frames' => $frames] : null;
            }

            if ($introducer === 0x21) {
                if ($position + 2 > $length) {
                    return null;
                }

                $label = ord($gif[$position + 1]);
                $end = self::skipSubBlocks($gif, $position + 2, $length);

                if ($end === null) {
                    return null;
                }

                $keep = $label === 0xF9 || ($label === 0xFF && self::keptApplication($gif, $position + 2));

                if ($keep) {
                    $out .= substr($gif, $position, $end - $position);
                }

                $position = $end;

                continue;
            }

            if ($introducer !== 0x2C || $position + 10 > $length) {
                return null;
            }

            $tableEnd = $position + 10 + self::tableSize(ord($gif[$position + 9]));
            $end = $tableEnd + 1 > $length ? null : self::skipSubBlocks($gif, $tableEnd + 1, $length);

            if ($end === null) {
                return null;
            }

            $out .= substr($gif, $position, $end - $position);
            $position = $end;
            $frames++;
        }

        return null;
    }

    /** Bytes of the colour table that a packed flag byte announces (0 without one). */
    private static function tableSize(int $packed): int
    {
        return ($packed & 0x80) !== 0 ? 3 * (1 << (($packed & 0x07) + 1)) : 0;
    }

    /** The offset after a series of data sub-blocks (and their zero terminator), or null when truncated. */
    private static function skipSubBlocks(string $gif, int $position, int $length): ?int
    {
        while ($position < $length) {
            $size = ord($gif[$position]);
            $position += 1 + $size;

            if ($size === 0) {
                return $position <= $length ? $position : null;
            }
        }

        return null;
    }

    private static function keptApplication(string $gif, int $position): bool
    {
        return ord($gif[$position] ?? "\0") === 11 && in_array(substr($gif, $position + 1, 11), self::KEPT_APPLICATIONS, true);
    }
}

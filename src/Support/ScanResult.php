<?php

namespace RatingsAndReviews\Support;

/**
 * The verdict of an UploadScanner. `reason` is for logs and the UploadRejected event (a
 * signature name, "ClamAV: Eicar-Test-Signature"); it is never shown to the member.
 */
final readonly class ScanResult
{
    private function __construct(
        public bool $clean,
        public ?string $reason = null,
    ) {}

    public static function clean(): self
    {
        return new self(true);
    }

    public static function infected(?string $reason = null): self
    {
        return new self(false, $reason === null || trim($reason) === '' ? null : mb_substr(trim($reason), 0, 255));
    }
}

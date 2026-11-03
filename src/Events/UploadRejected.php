<?php

namespace RatingsAndReviews\Events;

/**
 * An upload was refused by the UploadScanner (`cause` `infected`) or because the scanner
 * could not run (`cause` `scanner_error`). Nothing was stored. `reason` is the scanner's
 * text (or the exception message); `source` is `upload` (a member's file) or `import`
 * (Uploads::registerExisting()).
 */
final readonly class UploadRejected
{
    public const INFECTED = 'infected';

    public const SCANNER_ERROR = 'scanner_error';

    public function __construct(
        public int $userId,
        public string $cause,
        public ?string $reason,
        public ?string $filename,
        public ?string $mimeType,
        public int $size,
        public string $source = 'upload',
    ) {}
}

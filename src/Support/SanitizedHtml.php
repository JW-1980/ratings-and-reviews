<?php

namespace RatingsAndReviews\Support;

/**
 * Trusted HTML with its plain text, as produced by the HtmlSanitizer.
 */
final readonly class SanitizedHtml
{
    /**
     * @param  list<string>  $uploadPaths  Paths of rr_uploads rows referenced by kept images.
     */
    public function __construct(
        public string $html,
        public string $text,
        public int $imageCount = 0,
        public int $droppedImages = 0,
        public array $uploadPaths = [],
    ) {}

    public function isEmpty(): bool
    {
        return $this->text === '' && $this->imageCount === 0;
    }
}

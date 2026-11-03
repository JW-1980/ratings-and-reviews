<?php

namespace RatingsAndReviews\Support;

/**
 * The subject of an rr.moderate check that is not one item: a moderation list.
 *
 * The package asks the AbilityResolver about a concrete Review, Comment or Report whenever
 * it acts on one. For a list it cannot ask per row (pagination), so it asks per kind and
 * section with this value object and only lists what is allowed:
 *
 * - `inbox` true, `section` null: may the user open the list of this kind at all (moderate
 *   at least something of it)? A false answer is a 403.
 * - `inbox` false: may the user moderate the items of this kind in `section`? `section` is
 *   null for items whose section is unknown (rows written before sections were stored).
 *   For reports, `contentType` is the morph alias of the reported item (`rr_review`,
 *   `rr_comment` or a host alias) and `section` the section of that item.
 */
final readonly class ModerationScope
{
    public const REVIEW = 'review';

    public const COMMENT = 'comment';

    public const REPORT = 'report';

    public function __construct(
        public string $kind,
        public ?string $section = null,
        public ?string $contentType = null,
        public bool $inbox = false,
    ) {}

    public static function inbox(string $kind): self
    {
        return new self($kind, inbox: true);
    }

    public static function section(string $kind, ?string $section, ?string $contentType = null): self
    {
        return new self($kind, $section, $contentType);
    }
}

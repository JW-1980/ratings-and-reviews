<?php

namespace RatingsAndReviews\Support;

/**
 * The subject of an rr.manage-settings check for one section's settings
 * (`GET|PATCH admin/sections/{section}`, and the filter of `GET admin/sections`).
 */
final readonly class SectionScope
{
    public function __construct(public string $section) {}
}

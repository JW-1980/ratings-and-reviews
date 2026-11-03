<?php

namespace RatingsAndReviews\Contracts;

/**
 * Content that can be reported and taken down. Implemented by the package's Review and
 * Comment models; a host model implementing it can be reported as well.
 */
interface Moderatable
{
    /** The id of the user who wrote it, so they cannot report their own content. */
    public function moderationAuthorId(): ?int;

    /** Whether it is currently visible to the public (only visible content can be reported). */
    public function isPubliclyVisible(): bool;

    /**
     * What a moderator needs to judge the report later, even when the content was edited or
     * removed in the meantime.
     *
     * @return array<string, mixed>
     */
    public function moderationSnapshot(): array;

    /** Removes the content from public view as the outcome of a report. */
    public function takeDown(int|string $moderatorId, ?string $note = null): void;
}

<?php

namespace RatingsAndReviews\Tests\Fixtures;

use Illuminate\Contracts\Auth\Authenticatable;
use RatingsAndReviews\Contracts\ContentAnnotator;
use RatingsAndReviews\Models\Comment;
use RatingsAndReviews\Models\Review;
use RatingsAndReviews\Services\Annotations;
use RatingsAndReviews\Support\Annotation;
use Throwable;

/** A host annotator for tests: flags text that contains "geneest", and records every call. */
class RecordingAnnotator implements ContentAnnotator
{
    /** @var list<array{keys: list<string>, viewer: int|string|null}> */
    public array $calls = [];

    /** @var array<string, list<mixed>> extra annotations by item key, set by the test */
    public array $fixed = [];

    public ?Throwable $throws = null;

    public function annotate(array $items, ?Authenticatable $viewer): array
    {
        $this->calls[] = ['keys' => array_map(fn (Review|Comment $item): string => Annotations::keyOf($item), $items), 'viewer' => $viewer?->getAuthIdentifier()];

        if ($this->throws !== null) {
            throw $this->throws;
        }

        $result = $this->fixed;

        foreach ($items as $item) {
            if (str_contains((string) $item->body_text, 'geneest')) {
                $result[Annotations::keyOf($item)][] = new Annotation('health_claim', 'Gezondheidsclaim', 'warning', 'https://example.test/claims');
            }
        }

        return $result;
    }
}

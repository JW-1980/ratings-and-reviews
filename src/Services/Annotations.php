<?php

namespace RatingsAndReviews\Services;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use RatingsAndReviews\Contracts\ContentAnnotator;
use RatingsAndReviews\Models\Comment;
use RatingsAndReviews\Models\Review;
use RatingsAndReviews\Support\Annotation;
use Throwable;

/**
 * Runs the host's ContentAnnotator once for a list and presents the result as the
 * `annotations` of the review and comment resources.
 */
class Annotations
{
    /** Annotations shown per item; more are dropped (a header badge row, not a list). */
    public const MAX_PER_ITEM = 5;

    public function __construct(private readonly ContentAnnotator $annotator) {}

    /** The key a ContentAnnotator result uses for an item. */
    public static function keyOf(Review|Comment $item): string
    {
        return ($item instanceof Review ? 'review:' : 'comment:').$item->getKey();
    }

    /**
     * @param  iterable<Review|Comment|null>  $items
     * @return array<string, list<array{key: string, label: string, tone: string, url: string|null}>> keyed by keyOf()
     */
    public function forItems(iterable $items, ?Authenticatable $viewer): array
    {
        $unique = [];

        foreach ($items as $item) {
            if (($item instanceof Review || $item instanceof Comment) && $item->exists && ! ($item instanceof Comment && $item->isPlaceholder())) {
                $unique[self::keyOf($item)] = $item;
            }
        }

        if ($unique === []) {
            return [];
        }

        try {
            $result = $this->annotator->annotate(array_values($unique), $viewer);
        } catch (Throwable $exception) {
            if (config('app.debug')) {
                throw $exception;
            }

            report($exception);

            return [];
        }

        $presented = [];

        foreach ($result as $key => $annotations) {
            if (! is_string($key) || ! isset($unique[$key]) || ! is_iterable($annotations)) {
                continue;
            }

            $list = [];

            foreach ($annotations as $annotation) {
                $row = $annotation instanceof Annotation ? $annotation->toArray() : null;

                if ($row !== null && ! isset($list[$row['key']])) {
                    $list[$row['key']] = $row;
                }
            }

            if ($list !== []) {
                $presented[$key] = array_slice(array_values($list), 0, self::MAX_PER_ITEM);
            }
        }

        return $presented;
    }

    /**
     * Every comment of a loaded tree (roots and their loaded replies, recursively).
     *
     * @param  iterable<Comment>  $comments
     * @return list<Comment>
     */
    public static function flattenComments(iterable $comments): array
    {
        $flat = [];

        foreach ($comments as $comment) {
            $flat[] = $comment;

            if ($comment instanceof Model && $comment->relationLoaded('replies')) {
                array_push($flat, ...self::flattenComments($comment->getRelation('replies')));
            }
        }

        return $flat;
    }
}

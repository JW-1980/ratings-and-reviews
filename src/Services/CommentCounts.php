<?php

namespace RatingsAndReviews\Services;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use RatingsAndReviews\Contracts\SyncsCommentCount;
use RatingsAndReviews\Models\Comment;
use Throwable;

/**
 * Keeps a host's own comment counter in step (SyncsCommentCount): the published comments of
 * the subject that still have content. Called after the commit; a failing host counter is
 * reported and does not undo the change that triggered it.
 */
class CommentCounts
{
    public function publicCount(Model $subject): int
    {
        return Comment::query()->forSubject($subject)->published()->withContent()->count();
    }

    public function sync(?Model $subject): void
    {
        if (! $subject instanceof SyncsCommentCount) {
            return;
        }

        try {
            $subject->syncCommentCount($this->publicCount($subject));
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    /** By morph type and id; an unknown type or a missing subject is skipped. */
    public function syncByKey(string $type, int|string $id): void
    {
        $class = Relation::getMorphedModel($type);

        if ($class === null || ! is_subclass_of($class, Model::class) || ! is_subclass_of($class, SyncsCommentCount::class)) {
            return;
        }

        $this->sync($class::query()->find($id));
    }

    /**
     * The same for many subjects at once: one count per type and chunk, one host callback per
     * subject (the callback itself is per subject).
     *
     * @param  list<array{0: string, 1: int|string}>  $keys
     */
    public function syncMany(array $keys): void
    {
        foreach (array_chunk($keys, 200) as $chunk) {
            $byType = [];

            foreach ($chunk as [$type, $id]) {
                $byType[$type][] = (int) $id;
            }

            foreach ($byType as $type => $ids) {
                $class = Relation::getMorphedModel($type);

                if ($class === null || ! is_subclass_of($class, Model::class) || ! is_subclass_of($class, SyncsCommentCount::class)) {
                    continue;
                }

                $subjects = $class::query()->whereKey(array_values(array_unique($ids)))->get()->keyBy(fn (Model $subject): int => (int) $subject->getKey());
                $counts = Comment::query()->published()->withContent()
                    ->where('commentable_type', $type)
                    ->whereIn('commentable_id', array_values(array_unique($ids)))
                    ->toBase()
                    ->selectRaw('commentable_id, COUNT(*) as total')
                    ->groupBy('commentable_id')
                    ->pluck('total', 'commentable_id')
                    ->mapWithKeys(fn (mixed $total, mixed $id): array => [(int) $id => (int) $total]);

                foreach ($subjects as $id => $subject) {
                    try {
                        $subject->syncCommentCount((int) ($counts[$id] ?? 0));
                    } catch (Throwable $exception) {
                        report($exception);
                    }
                }
            }
        }
    }
}

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
}

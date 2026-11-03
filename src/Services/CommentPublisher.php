<?php

namespace RatingsAndReviews\Services;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Facades\DB;
use RatingsAndReviews\Events\CommentPosted;
use RatingsAndReviews\Models\Comment;
use RatingsAndReviews\Support\ContentStatus;

/**
 * Publishes comments whose undo window has ended: sets `published_at` under the comment's
 * row lock (so an undo arriving at the same moment either wins or sees it published) and
 * only then fires CommentPosted, the event hosts send notifications on. A comment that is
 * pending, refused, deleted or undone is skipped; a pending one is published by the
 * moderator's approval instead.
 *
 * Run by the delayed PublishScheduledComment job and, as a safety net for sync or stalled
 * queues, by `rr:publish-due` every minute.
 */
class CommentPublisher
{
    public function __construct(
        private readonly Dispatcher $events,
        private readonly Participation $participation,
    ) {}

    /** Whether this call published it. */
    public function publish(int $commentId): bool
    {
        $comment = DB::transaction(function () use ($commentId): ?Comment {
            $comment = Comment::query()->whereKey($commentId)->lockForUpdate()->first();

            if ($comment === null
                || $comment->published_at !== null
                || $comment->status !== ContentStatus::Approved
                || $comment->publish_at === null
                || $comment->publish_at->isFuture()) {
                return null;
            }

            $comment->forceFill(['published_at' => now()])->save();

            return $comment;
        });

        if ($comment === null) {
            return false;
        }

        $this->events->dispatch(new CommentPosted($comment, $this->participation->recipientsFor($comment)));

        return true;
    }

    /** Publishes every due comment, oldest first; returns how many were published. */
    public function publishDue(?int $limit = null): int
    {
        $limit ??= max(1, (int) config('ratings-and-reviews.publisher.batch_size', 500));
        $published = 0;

        $ids = Comment::query()
            ->where('status', ContentStatus::Approved->value)
            ->whereNull('published_at')
            ->whereNotNull('publish_at')
            ->where('publish_at', '<=', now())
            ->orderBy('publish_at')
            ->orderBy('id')
            ->limit($limit)
            ->pluck('id');

        foreach ($ids as $id) {
            $published += $this->publish((int) $id) ? 1 : 0;
        }

        return $published;
    }
}

<?php

namespace RatingsAndReviews\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use RatingsAndReviews\Models\Comment;
use RatingsAndReviews\Services\CommentPublisher;

/**
 * Publishes one comment when its undo window ends. Dispatched with a delay after the
 * posting transaction commits; on a queue that ran it early (a sync queue ignores delays)
 * it is released until the window ends, and `rr:publish-due` catches any it missed.
 */
class PublishScheduledComment implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 5;

    public function __construct(public int $commentId)
    {
        $this->afterCommit();
    }

    public function handle(CommentPublisher $publisher): void
    {
        if ($publisher->publish($this->commentId) || $this->job === null) {
            return;
        }

        $publishAt = Comment::query()->whereKey($this->commentId)->whereNull('published_at')->value('publish_at');

        if ($publishAt !== null && now()->lt($publishAt)) {
            $this->release(max(1, (int) ceil(now()->diffInSeconds($publishAt, true))));
        }
    }
}

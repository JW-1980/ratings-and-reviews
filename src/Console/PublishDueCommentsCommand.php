<?php

namespace RatingsAndReviews\Console;

use Illuminate\Console\Command;
use RatingsAndReviews\Services\CommentPublisher;

class PublishDueCommentsCommand extends Command
{
    protected $signature = 'rr:publish-due {--limit= : Maximum comments to publish in this run}';

    protected $description = 'Publish comments whose undo window has ended and announce them (CommentPosted).';

    public function handle(CommentPublisher $publisher): int
    {
        $limit = $this->option('limit');
        $published = $publisher->publishDue(is_numeric($limit) ? max(1, (int) $limit) : null);

        $this->components->info("Published {$published} comment(s).");

        return self::SUCCESS;
    }
}

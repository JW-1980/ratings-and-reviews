<?php

namespace RatingsAndReviews\Console;

use Illuminate\Console\Command;
use RatingsAndReviews\Services\Uploads;

class PruneUploadsCommand extends Command
{
    protected $signature = 'rr:prune-uploads {--hours= : Grace period in hours (default: uploads.prune_after_hours)}';

    protected $description = 'Delete uploads (file and row) that were never attached to a review or comment within the grace period.';

    public function handle(Uploads $uploads): int
    {
        $hours = $this->option('hours');

        if ($hours !== null && (! is_numeric($hours) || (int) $hours < 0)) {
            $this->components->error('--hours must be a number of hours (0 or more).');

            return self::INVALID;
        }

        $deleted = $uploads->prune($hours === null ? null : (int) $hours);

        $this->components->info("Deleted {$deleted} unattached upload(s).");

        return self::SUCCESS;
    }
}

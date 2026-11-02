<?php

namespace RatingsAndReviews\Console;

use Illuminate\Console\Command;
use RatingsAndReviews\Services\ReviewInvitations;

class SendReviewInvitationsCommand extends Command
{
    protected $signature = 'rr:send-review-invitations {--limit= : Maximum invitations to handle in this run}';

    protected $description = 'Fire ReviewInvitationDue for review invitations that are due (the host sends them).';

    public function handle(ReviewInvitations $invitations): int
    {
        $limit = $this->option('limit');
        $result = $invitations->sendDue(is_numeric($limit) ? max(1, (int) $limit) : null);

        $this->components->info("Sent {$result['sent']}, completed {$result['completed']}, removed {$result['removed']}, waiting {$result['waiting']}.");

        return self::SUCCESS;
    }
}

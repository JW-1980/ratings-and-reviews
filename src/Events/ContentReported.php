<?php

namespace RatingsAndReviews\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use RatingsAndReviews\Models\Report;

final readonly class ContentReported implements ShouldDispatchAfterCommit
{
    public function __construct(public Report $report) {}
}

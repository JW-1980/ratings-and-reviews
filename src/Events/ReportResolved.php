<?php

namespace RatingsAndReviews\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use RatingsAndReviews\Models\Report;

/** A moderator resolved or dismissed a report (tell the reporter, if the host wants). */
final readonly class ReportResolved implements ShouldDispatchAfterCommit
{
    public function __construct(
        public Report $report,
        public bool $tookDown,
    ) {}
}

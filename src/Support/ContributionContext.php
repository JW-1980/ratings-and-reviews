<?php

namespace RatingsAndReviews\Support;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

/**
 * Everything RiskSignals needs to decide whether a review or comment is held or counted.
 */
final readonly class ContributionContext
{
    /**
     * @param  'review'|'comment'  $kind
     */
    public function __construct(
        public string $kind,
        public int $userId,
        public Model $subject,
        public string $text,
        public ?Request $request,
        public ?string $networkHash,
        public ?string $deviceHash,
        public bool $isEdit = false,
    ) {}
}

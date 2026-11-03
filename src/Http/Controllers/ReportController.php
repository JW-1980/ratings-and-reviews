<?php

namespace RatingsAndReviews\Http\Controllers;

use Illuminate\Http\JsonResponse;
use RatingsAndReviews\Contracts\Commentable;
use RatingsAndReviews\Contracts\Moderatable;
use RatingsAndReviews\Contracts\Reviewable;
use RatingsAndReviews\Http\Requests\StoreReportRequest;
use RatingsAndReviews\Models\Comment;
use RatingsAndReviews\Models\Review;
use RatingsAndReviews\Services\ReportService;
use RatingsAndReviews\Services\SubjectResolver;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class ReportController extends Controller
{
    public function __construct(
        private readonly SubjectResolver $subjects,
        private readonly ReportService $reports,
    ) {}

    public function store(StoreReportRequest $request): JsonResponse
    {
        $user = $this->requireUser($request);
        $item = $this->subjects->resolve((string) $request->validated('reportable_type'), (string) $request->validated('reportable_id'), Moderatable::class);

        $subject = match (true) {
            $item instanceof Review => $item->reviewable,
            $item instanceof Comment => $item->commentable,
            default => null,
        };

        if (($item instanceof Review && (! $subject instanceof Reviewable || ! $subject->reviewsVisibleTo($user)))
            || ($item instanceof Comment && (! $subject instanceof Commentable || ! $subject->commentsVisibleTo($user)))) {
            throw new NotFoundHttpException;
        }

        $details = $request->validated('details');
        $report = $this->reports->report($user, $item, (string) $request->validated('reason'), is_string($details) ? $details : null);

        return new JsonResponse([
            'data' => ['id' => $report->id, 'status' => $report->status->value],
            'message' => __('ratings-and-reviews::messages.report_received'),
        ], 201);
    }
}

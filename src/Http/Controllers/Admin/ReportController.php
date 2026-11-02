<?php

namespace RatingsAndReviews\Http\Controllers\Admin;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RatingsAndReviews\Http\Controllers\Controller;
use RatingsAndReviews\Http\Requests\Admin\ResolveReportRequest;
use RatingsAndReviews\Http\Resources\ReportResource;
use RatingsAndReviews\Models\Report;
use RatingsAndReviews\Services\ReportService;
use RatingsAndReviews\Support\Ability;
use RatingsAndReviews\Support\ReportStatus;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class ReportController extends Controller
{
    public function __construct(private readonly ReportService $reports) {}

    public function index(Request $request): JsonResponse
    {
        $this->requireAbility($request, Ability::MODERATE);
        $status = ReportStatus::tryFrom((string) $request->query('status', 'open')) ?? ReportStatus::Open;

        $page = Report::query()
            ->where('status', $status->value)
            ->orderBy('created_at', $status === ReportStatus::Open ? 'asc' : 'desc')
            ->orderBy('id')
            ->paginate(25)
            ->withQueryString();

        return new JsonResponse([
            'data' => ReportResource::collection(collect($page->items()))->resolve($request),
            'meta' => $this->paginationMeta($page) + ['status' => $status->value],
        ]);
    }

    public function update(ResolveReportRequest $request, string $report): JsonResponse
    {
        $user = $this->requireUser($request);
        $model = (ctype_digit($report) ? Report::query()->find((int) $report) : null) ?? throw new NotFoundHttpException;

        $model = $this->reports->resolve(
            $model,
            $user->getAuthIdentifier(),
            ReportStatus::from((string) $request->validated('status')),
            $request->validated('note'),
            (bool) $request->validated('take_down', false),
        );

        return new JsonResponse(['data' => (new ReportResource($model))->resolve($request)]);
    }
}

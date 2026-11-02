<?php

namespace RatingsAndReviews\Http\Controllers\Admin;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RatingsAndReviews\Contracts\AbilityResolver;
use RatingsAndReviews\Http\Controllers\Controller;
use RatingsAndReviews\Http\Requests\Admin\ResolveReportRequest;
use RatingsAndReviews\Http\Resources\ReportResource;
use RatingsAndReviews\Models\Report;
use RatingsAndReviews\Services\ReportService;
use RatingsAndReviews\Support\Ability;
use RatingsAndReviews\Support\ModerationScope;
use RatingsAndReviews\Support\ReportStatus;

class ReportController extends Controller
{
    public function __construct(private readonly ReportService $reports) {}

    /**
     * The reports of one status. The user needs rr.moderate on the report inbox
     * (ModerationScope::inbox('report')); the list holds only the reports whose item type and
     * section they may moderate (ModerationScope::section('report', section, type)).
     */
    public function index(Request $request): JsonResponse
    {
        $user = $this->requireAbility($request, Ability::MODERATE, ModerationScope::inbox(ModerationScope::REPORT));
        $status = ReportStatus::tryFrom((string) $request->query('status', 'open')) ?? ReportStatus::Open;
        $resolver = app(AbilityResolver::class);

        $allowed = Report::query()->where('status', $status->value)->toBase()->distinct()->get(['reportable_type', 'section'])
            ->filter(fn (object $row): bool => $resolver->allows($user, Ability::MODERATE, ModerationScope::section(ModerationScope::REPORT, $row->section === null ? null : (string) $row->section, (string) $row->reportable_type)))
            ->values();

        $page = Report::query()
            ->where('status', $status->value)
            ->where(function (Builder $query) use ($allowed): void {
                $query->whereRaw('0 = 1');

                foreach ($allowed as $row) {
                    $query->orWhere(fn (Builder $pair) => $pair->where('reportable_type', $row->reportable_type)
                        ->when($row->section === null, fn (Builder $q) => $q->whereNull('section'), fn (Builder $q) => $q->where('section', $row->section)));
                }
            })
            ->orderBy('created_at', $status === ReportStatus::Open ? 'asc' : 'desc')
            ->orderBy('id')
            ->paginate(25)
            ->withQueryString();

        return new JsonResponse([
            'data' => ReportResource::collection(collect($page->items()))->resolve($request),
            'meta' => $this->paginationMeta($page) + ['status' => $status->value, 'scopes' => $allowed->map(fn (object $row): array => ['type' => (string) $row->reportable_type, 'section' => $row->section])->all()],
        ]);
    }

    public function update(ResolveReportRequest $request, string $report): JsonResponse
    {
        $user = $this->requireUser($request);
        $model = $request->report();

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

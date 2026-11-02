<?php

namespace RatingsAndReviews\Services;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RatingsAndReviews\Contracts\Commentable;
use RatingsAndReviews\Contracts\Moderatable;
use RatingsAndReviews\Contracts\Reviewable;
use RatingsAndReviews\Events\ContentReported;
use RatingsAndReviews\Events\ReportResolved;
use RatingsAndReviews\Models\Comment;
use RatingsAndReviews\Models\Report;
use RatingsAndReviews\Models\Review;
use RatingsAndReviews\Support\ReportStatus;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Reports on reviews, comments and other Moderatable content.
 *
 * One open report per reporter and item, enforced by a unique index over a generated
 * "open" marker, so a double submit cannot create two. The report keeps a snapshot of the
 * content as it was reported. Handling records who handled it and when; taking the content
 * down goes through Moderatable::takeDown().
 */
class ReportService
{
    public function __construct(private readonly Dispatcher $events) {}

    /**
     * @return list<string>
     */
    public function reasons(): array
    {
        return array_values(array_map('strval', (array) config('ratings-and-reviews.reports.reasons', [])));
    }

    /**
     * @throws ValidationException
     * @throws AccessDeniedHttpException on the reporter's own content
     */
    public function report(Authenticatable $reporter, Model&Moderatable $item, string $reason, ?string $details = null): Report
    {
        $reporterId = (int) $reporter->getAuthIdentifier();
        $details = $details === null ? null : trim(strip_tags($details));
        $details = $details === '' ? null : $details;

        if (! in_array($reason, $this->reasons(), true)) {
            throw ValidationException::withMessages(['reason' => __('ratings-and-reviews::messages.report_reason_invalid')]);
        }

        if ($details === null && in_array($reason, (array) config('ratings-and-reviews.reports.details_required_for', []), true)) {
            throw ValidationException::withMessages(['details' => __('ratings-and-reviews::messages.report_details_required')]);
        }

        if (! $item->isPubliclyVisible()) {
            throw ValidationException::withMessages(['reportable_id' => __('ratings-and-reviews::messages.report_unavailable')]);
        }

        if ($item->moderationAuthorId() === $reporterId) {
            throw new AccessDeniedHttpException((string) __('ratings-and-reviews::messages.report_own'));
        }

        try {
            $report = DB::transaction(function () use ($item, $reporterId, $reason, $details): Report {
                $exists = Report::query()->open()
                    ->where('reportable_type', $item->getMorphClass())
                    ->where('reportable_id', $item->getKey())
                    ->where('reporter_id', $reporterId)
                    ->lockForUpdate()
                    ->exists();

                if ($exists) {
                    throw ValidationException::withMessages(['reportable_id' => __('ratings-and-reviews::messages.report_exists')]);
                }

                $report = Report::query()->create([
                    'reportable_type' => $item->getMorphClass(),
                    'reportable_id' => $item->getKey(),
                    'section' => self::sectionOf($item),
                    'reporter_id' => $reporterId,
                    'reason' => $reason,
                    'details' => $details,
                    'snapshot' => $item->moderationSnapshot(),
                    'status' => ReportStatus::Open,
                ]);

                $this->recountFlags($item);

                return $report;
            });
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['reportable_id' => __('ratings-and-reviews::messages.report_exists')]);
        }

        $this->events->dispatch(new ContentReported($report));

        return $report;
    }

    /**
     * Resolves or dismisses an open report. `$takeDown` removes the content from view.
     *
     * @throws ValidationException when the report was already handled
     */
    public function resolve(Report $report, int|string $moderatorId, ReportStatus $status, ?string $note = null, bool $takeDown = false): Report
    {
        if ($status === ReportStatus::Open) {
            throw ValidationException::withMessages(['status' => __('ratings-and-reviews::messages.report_status_invalid')]);
        }

        DB::transaction(function () use ($report, $moderatorId, $status, $note): void {
            $locked = Report::query()->whereKey($report->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== ReportStatus::Open) {
                throw ValidationException::withMessages(['status' => __('ratings-and-reviews::messages.report_handled')]);
            }

            $report->forceFill([
                'status' => $status,
                'handled_by' => (int) $moderatorId,
                'handled_at' => now(),
                'resolution_note' => $note === null || trim($note) === '' ? null : trim($note),
            ])->save();
        });

        $item = $this->reportedItem($report);
        $tookDown = false;

        if ($item instanceof Moderatable) {
            if ($takeDown && $status === ReportStatus::Resolved) {
                $item->takeDown($moderatorId, $note);
                $tookDown = true;
            }

            $this->recountFlags($item);
        }

        $this->events->dispatch(new ReportResolved($report, $tookDown));

        return $report;
    }

    /** The reported item, also when it was soft-deleted in the meantime. */
    public function reportedItem(Report $report): ?Model
    {
        $class = Relation::getMorphedModel($report->reportable_type) ?? $report->reportable_type;

        if (! class_exists($class) || ! is_subclass_of($class, Model::class)) {
            return null;
        }

        return $class::query()->withoutGlobalScopes()->find($report->reportable_id);
    }

    /** Keeps `flags_count` (when the item has one) equal to its number of open reports. */
    /**
     * The section of a reported item: a review's or comment's own, a host item's comment or
     * review section, otherwise null.
     */
    public static function sectionOf(Model $item): ?string
    {
        return match (true) {
            $item instanceof Review, $item instanceof Comment => $item->section,
            $item instanceof Commentable => $item->commentSectionKey(),
            $item instanceof Reviewable => $item->reviewSectionKey(),
            default => null,
        };
    }

    private function recountFlags(Model $item): void
    {
        if (! array_key_exists('flags_count', $item->getAttributes())) {
            return;
        }

        $open = Report::query()->open()
            ->where('reportable_type', $item->getMorphClass())
            ->where('reportable_id', $item->getKey())
            ->count();

        $item->newQueryWithoutScopes()->whereKey($item->getKey())->toBase()->update(['flags_count' => $open]);
        $item->forceFill(['flags_count' => $open])->syncOriginalAttribute('flags_count');
    }
}

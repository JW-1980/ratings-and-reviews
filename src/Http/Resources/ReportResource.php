<?php

namespace RatingsAndReviews\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use RatingsAndReviews\Models\Report;

/**
 * @property Report $resource
 */
class ReportResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $report = $this->resource;

        return [
            'id' => $report->id,
            'reportable' => ['type' => $report->reportable_type, 'id' => $report->reportable_id],
            'reporter_id' => $report->reporter_id,
            'reason' => $report->reason,
            'reason_label' => __('ratings-and-reviews::enums.report_reason.'.$report->reason),
            'details' => $report->details,
            'snapshot' => $report->snapshot,
            'status' => $report->status->value,
            'status_label' => $report->status->label(),
            'handled_by' => $report->handled_by,
            'handled_at' => $report->handled_at?->toIso8601String(),
            'resolution_note' => $report->resolution_note,
            'created_at' => $report->created_at?->toIso8601String(),
        ];
    }
}

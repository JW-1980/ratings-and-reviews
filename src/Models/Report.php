<?php

namespace RatingsAndReviews\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;
use RatingsAndReviews\Database\Factories\ReportFactory;
use RatingsAndReviews\Support\ReportStatus;

/**
 * @property int $id
 * @property string $reportable_type
 * @property int $reportable_id
 * @property string|null $section the section of the reported item (null: a host item without one)
 * @property int $reporter_id
 * @property string $reason
 * @property string|null $details
 * @property array<string, mixed>|null $snapshot
 * @property ReportStatus $status
 * @property int|null $handled_by
 * @property Carbon|null $handled_at
 * @property string|null $resolution_note
 * @property Carbon|null $created_at
 */
class Report extends Model
{
    /** @use HasFactory<ReportFactory> */
    use HasFactory;

    protected $table = 'rr_reports';

    protected $fillable = [
        'reportable_type', 'reportable_id', 'section', 'reporter_id', 'reason', 'details', 'snapshot', 'status', 'handled_by',
        'handled_at', 'resolution_note',
    ];

    protected function casts(): array
    {
        return [
            'reportable_id' => 'integer',
            'reporter_id' => 'integer',
            'snapshot' => 'array',
            'status' => ReportStatus::class,
            'handled_by' => 'integer',
            'handled_at' => 'datetime',
        ];
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function reportable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * @param  Builder<Report>  $query
     */
    public function scopeOpen(Builder $query): void
    {
        $query->where('status', ReportStatus::Open->value);
    }

    protected static function newFactory(): Factory
    {
        return ReportFactory::new();
    }
}

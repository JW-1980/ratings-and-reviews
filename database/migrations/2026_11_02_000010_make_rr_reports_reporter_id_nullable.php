<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * rr_reports.reporter_id becomes nullable: erasing a user (Services\Erasure::purge) unlinks
 * them from the reports they filed while the report and its snapshot stay for the
 * moderators. Fresh installs create the column nullable already (migration 000004); this
 * changes databases migrated before, and is skipped where the column already allows null.
 */
return new class extends Migration
{
    public function up(): void
    {
        if ($this->reporterIsNullable()) {
            return;
        }

        Schema::table('rr_reports', function (Blueprint $table): void {
            $table->unsignedBigInteger('reporter_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        // Not reversed: rows unlinked by an erasure cannot get their reporter back.
    }

    private function reporterIsNullable(): bool
    {
        foreach (Schema::getColumns('rr_reports') as $column) {
            if ($column['name'] === 'reporter_id') {
                return (bool) $column['nullable'];
            }
        }

        return false;
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Why a review does not count in the averages: `rr_reviews.uncounted_reason` (nullable,
 * like rr_votes and rr_topic_ratings). Additive and idempotent: existing uncounted reviews
 * keep `counted = false` without a reason.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('rr_reviews', 'uncounted_reason')) {
            return;
        }

        Schema::table('rr_reviews', function (Blueprint $table): void {
            $table->string('uncounted_reason', 64)->nullable()->after('counted');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('rr_reviews', 'uncounted_reason')) {
            return;
        }

        Schema::table('rr_reviews', function (Blueprint $table): void {
            $table->dropColumn('uncounted_reason');
        });
    }
};

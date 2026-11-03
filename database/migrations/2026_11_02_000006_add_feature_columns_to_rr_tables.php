<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Columns the version-1 features need that the core tables lack:
 *
 * - rr_comments.counted: the RiskSignals verdict for a comment. An uncounted comment is shown
 *   like any other but does not count as a contribution (trust levels, digest totals, the
 *   CommentPosted payload a host awards points on). Existing rows count (default true).
 * - rr_rating_aggregates.facet_counts / monthly / comparisons: per-subject totals computed by
 *   RefreshRatingAggregates, read by the summary (context facets, score trend, "compared with").
 *   Null until the next refresh, which the summary treats as "no data yet".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rr_comments', function (Blueprint $table): void {
            $table->boolean('counted')->default(true)->after('status');
        });

        Schema::table('rr_rating_aggregates', function (Blueprint $table): void {
            $table->json('facet_counts')->nullable()->after('topics');
            $table->json('monthly')->nullable()->after('facet_counts');
            $table->json('comparisons')->nullable()->after('monthly');
        });

        Schema::table('rr_reviews', function (Blueprint $table): void {
            $table->index(['compared_type', 'compared_id'], 'rr_reviews_compared_index');
        });
    }

    public function down(): void
    {
        Schema::table('rr_reviews', function (Blueprint $table): void {
            $table->dropIndex('rr_reviews_compared_index');
        });

        Schema::table('rr_rating_aggregates', function (Blueprint $table): void {
            $table->dropColumn(['facet_counts', 'monthly', 'comparisons']);
        });

        Schema::table('rr_comments', function (Blueprint $table): void {
            $table->dropColumn('counted');
        });
    }
};

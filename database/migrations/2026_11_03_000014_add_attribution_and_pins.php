<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Posting "as" a brand or shop (AttributionResolver): `attribution` (json snapshot
 * {key, label, url}) on rr_comments and rr_reviews. Pinning one top-level comment per
 * discussion: `pinned_at`, `pinned_by` on rr_comments. Additive: existing rows stay
 * unattributed and unpinned.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rr_comments', function (Blueprint $table): void {
            if (! Schema::hasColumn('rr_comments', 'attribution')) {
                $table->json('attribution')->nullable()->after('quote_text');
            }

            if (! Schema::hasColumn('rr_comments', 'pinned_at')) {
                $table->timestamp('pinned_at')->nullable()->after('attribution');
                $table->unsignedBigInteger('pinned_by')->nullable()->after('pinned_at');
                $table->index(['commentable_type', 'commentable_id', 'pinned_at'], 'rr_comments_pinned_index');
            }
        });

        Schema::table('rr_reviews', function (Blueprint $table): void {
            if (! Schema::hasColumn('rr_reviews', 'attribution')) {
                $table->json('attribution')->nullable()->after('cons');
            }
        });
    }

    public function down(): void
    {
        Schema::table('rr_reviews', function (Blueprint $table): void {
            $table->dropColumn('attribution');
        });

        Schema::table('rr_comments', function (Blueprint $table): void {
            $table->dropIndex('rr_comments_pinned_index');
            $table->dropColumn(['attribution', 'pinned_at', 'pinned_by']);
        });
    }
};

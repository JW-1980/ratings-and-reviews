<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Erasure support (Services\Erasure):
 *
 * - erasure_withheld_at on rr_reviews, rr_comments and rr_topic_ratings: set while a
 *   member's erasure request is pending. Withheld reviews and topic scores are left out of
 *   every query (a global scope), withheld comments are left out of public reads, counts,
 *   digests and search and shown as a placeholder where replies hang beneath them.
 * - rr_comments.user_id nullable: a purged comment that still holds replies stays as an
 *   anonymous placeholder. Fresh installs create it nullable already (migration 000003).
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['rr_reviews', 'rr_comments', 'rr_topic_ratings'] as $table) {
            Schema::table($table, function (Blueprint $blueprint) use ($table): void {
                $blueprint->timestamp('erasure_withheld_at')->nullable();
                $blueprint->index(['user_id', 'erasure_withheld_at'], $table.'_erasure_index');
            });
        }

        if (! $this->nullable('rr_comments', 'user_id')) {
            Schema::table('rr_comments', function (Blueprint $table): void {
                $table->unsignedBigInteger('user_id')->nullable()->change();
            });
        }
    }

    public function down(): void
    {
        foreach (['rr_topic_ratings', 'rr_comments', 'rr_reviews'] as $table) {
            Schema::table($table, function (Blueprint $blueprint) use ($table): void {
                $blueprint->dropIndex($table.'_erasure_index');
                $blueprint->dropColumn('erasure_withheld_at');
            });
        }
    }

    private function nullable(string $table, string $column): bool
    {
        foreach (Schema::getColumns($table) as $info) {
            if ($info['name'] === $column) {
                return (bool) $info['nullable'];
            }
        }

        return false;
    }
};

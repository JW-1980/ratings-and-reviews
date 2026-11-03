<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Indexes for the queries the package actually runs: topic lookups (which do not use the
 * review-first unique index), "newest" review lists, and the publisher that finds published
 * comments whose undo window ended.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rr_review_topic_ratings', function (Blueprint $table): void {
            $table->index(['topic_id', 'review_id'], 'rr_review_topic_ratings_topic_index');
        });

        Schema::table('rr_topic_ratings', function (Blueprint $table): void {
            $table->index(['topic_id', 'reviewable_type', 'reviewable_id', 'counted'], 'rr_topic_ratings_topic_index');
        });

        Schema::table('rr_reviews', function (Blueprint $table): void {
            $table->index(['reviewable_type', 'reviewable_id', 'status', 'created_at', 'id'], 'rr_reviews_subject_newest_index');
        });

        Schema::table('rr_comments', function (Blueprint $table): void {
            $table->index(['status', 'published_at', 'publish_at'], 'rr_comments_publish_index');
        });
    }

    public function down(): void
    {
        Schema::table('rr_comments', function (Blueprint $table): void {
            $table->dropIndex('rr_comments_publish_index');
        });

        Schema::table('rr_reviews', function (Blueprint $table): void {
            $table->dropIndex('rr_reviews_subject_newest_index');
        });

        Schema::table('rr_topic_ratings', function (Blueprint $table): void {
            $table->dropIndex('rr_topic_ratings_topic_index');
        });

        Schema::table('rr_review_topic_ratings', function (Blueprint $table): void {
            $table->dropIndex('rr_review_topic_ratings_topic_index');
        });
    }
};

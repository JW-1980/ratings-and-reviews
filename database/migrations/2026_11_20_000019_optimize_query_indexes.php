<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Index changes from the database performance audit of 20 November 2026 (measured with
 * EXPLAIN ANALYZE on MySQL 8.4; see docs/architectuur.md).
 *
 * Replaced, because the old index could not deliver the ORDER BY or WHERE it was made for:
 *
 * - `rr_reviews_subject_status_index` ended at `helpful_count`, while the default review list
 *   sorts on `helpful_count`, `created_at`, `id`: every page sorted all reviews of the subject
 *   (filesort). `rr_reviews_subject_helpful_index` carries the whole sort.
 * - `rr_comments_thread_index` ended at `helpful_score`, while the default discussion sort is
 *   `helpful_score`, `helpful_count`, `created_at`, `id`. `rr_comments_thread_helpful_index`
 *   carries the whole sort; it starts with the same columns, so everything that used the old
 *   index uses the new one.
 * - `rr_review_invitations_due_index` (`due_at`, `sent_at`) made rr:send-review-invitations
 *   walk every invitation that was ever due. `rr_review_invitations_open_index` starts with the
 *   three "still open" columns, so the run reads the open invitations only.
 *
 * Dropped, because a composite index starting with the same column serves the same queries:
 * the single-column `user_id` indexes of `rr_votes` and `rr_poll_votes` (000018 added
 * `user_id, …` composites) and of `rr_topic_ratings` (`rr_topic_ratings_erasure_index`).
 *
 * A new index is added before the one it replaces is dropped. Every step checks the schema
 * first, so the migration can run again and also upgrades a database that never had one of
 * the old indexes.
 */
return new class extends Migration
{
    /** [table, new index, columns, replaced index, columns of the replaced index] */
    private const REPLACED = [
        ['rr_reviews', 'rr_reviews_subject_helpful_index', ['reviewable_type', 'reviewable_id', 'status', 'helpful_count', 'created_at', 'id'], 'rr_reviews_subject_status_index', ['reviewable_type', 'reviewable_id', 'status', 'helpful_count']],
        ['rr_comments', 'rr_comments_thread_helpful_index', ['commentable_type', 'commentable_id', 'status', 'parent_id', 'helpful_score', 'helpful_count', 'created_at', 'id'], 'rr_comments_thread_index', ['commentable_type', 'commentable_id', 'status', 'parent_id', 'helpful_score']],
        ['rr_review_invitations', 'rr_review_invitations_open_index', ['sent_at', 'completed_at', 'dismissed_at', 'due_at'], 'rr_review_invitations_due_index', ['due_at', 'sent_at']],
    ];

    /** [table, redundant single-column index, the composite index that serves its queries] */
    private const REDUNDANT = [
        ['rr_votes', 'rr_votes_user_id_index', 'rr_votes_user_item_index'],
        ['rr_poll_votes', 'rr_poll_votes_user_id_index', 'rr_poll_votes_user_poll_index'],
        ['rr_topic_ratings', 'rr_topic_ratings_user_id_index', 'rr_topic_ratings_erasure_index'],
    ];

    public function up(): void
    {
        foreach (self::REPLACED as [$table, $new, $columns, $old]) {
            $this->addIndex($table, $new, $columns);
            $this->dropIndex($table, $old);
        }

        foreach (self::REDUNDANT as [$table, $single, $composite]) {
            // Only when the composite is there: `user_id` must stay indexed.
            if (Schema::hasIndex($table, $composite)) {
                $this->dropIndex($table, $single);
            }
        }
    }

    public function down(): void
    {
        foreach (self::REDUNDANT as [$table, $single]) {
            $this->addIndex($table, $single, ['user_id']);
        }

        foreach (self::REPLACED as [$table, $new, , $old, $oldColumns]) {
            $this->addIndex($table, $old, $oldColumns);
            $this->dropIndex($table, $new);
        }
    }

    /**
     * @param  list<string>  $columns
     */
    private function addIndex(string $table, string $name, array $columns): void
    {
        if (Schema::hasIndex($table, $name)) {
            return;
        }

        Schema::table($table, function (Blueprint $blueprint) use ($name, $columns): void {
            $blueprint->index($columns, $name);
        });
    }

    private function dropIndex(string $table, string $name): void
    {
        if (! Schema::hasIndex($table, $name)) {
            return;
        }

        Schema::table($table, function (Blueprint $blueprint) use ($name): void {
            $blueprint->dropIndex($name);
        });
    }
};

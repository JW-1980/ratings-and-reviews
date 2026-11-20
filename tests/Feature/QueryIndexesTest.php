<?php

namespace RatingsAndReviews\Tests\Feature;

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RatingsAndReviews\Tests\CommittedTestCase;

/**
 * The indexes of migration 000019 (database performance audit, 20 November 2026): the default
 * sorts are delivered by an index, the invitation run reads open invitations only, and no
 * index repeats the first columns of another one. DDL, so on the committed schema.
 */
class QueryIndexesTest extends CommittedTestCase
{
    private function migration(): Migration
    {
        return require __DIR__.'/../../database/migrations/2026_11_20_000019_optimize_query_indexes.php';
    }

    /**
     * @return array<string, list<string>> index name => columns
     */
    private function indexes(string $table): array
    {
        $indexes = [];

        foreach (Schema::getIndexes($table) as $index) {
            $indexes[$index['name']] = $index['columns'];
        }

        return $indexes;
    }

    /** The plan of a query that is told to use one index. */
    private function plan(string $sql): object
    {
        $plan = DB::select('EXPLAIN '.$sql);
        $this->assertCount(1, $plan);

        return $plan[0];
    }

    public function test_the_default_review_sort_is_read_from_the_index_without_sorting(): void
    {
        $plan = $this->plan('SELECT * FROM rr_reviews FORCE INDEX (rr_reviews_subject_helpful_index) '
            ."WHERE reviewable_type = 'product' AND reviewable_id = 1 AND status = 'approved' AND deleted_at IS NULL AND erasure_withheld_at IS NULL "
            .'ORDER BY helpful_count DESC, created_at DESC, id DESC LIMIT 10');

        $this->assertSame('rr_reviews_subject_helpful_index', $plan->key);
        $this->assertStringNotContainsString('filesort', (string) $plan->Extra);
    }

    public function test_the_default_discussion_sort_is_read_from_the_index_without_sorting(): void
    {
        $plan = $this->plan('SELECT * FROM rr_comments FORCE INDEX (rr_comments_thread_helpful_index) '
            ."WHERE commentable_type = 'product' AND commentable_id = 1 AND status = 'approved' AND published_at IS NOT NULL AND parent_id IS NULL AND pinned_at IS NULL AND deleted_at IS NULL "
            .'ORDER BY helpful_score DESC, helpful_count DESC, created_at DESC, id DESC LIMIT 20');

        $this->assertSame('rr_comments_thread_helpful_index', $plan->key);
        $this->assertStringNotContainsString('filesort', (string) $plan->Extra);
    }

    public function test_the_old_indexes_could_not_deliver_those_sorts(): void
    {
        $this->migration()->down();

        $reviews = $this->plan('SELECT * FROM rr_reviews FORCE INDEX (rr_reviews_subject_status_index) '
            ."WHERE reviewable_type = 'product' AND reviewable_id = 1 AND status = 'approved' ORDER BY helpful_count DESC, created_at DESC, id DESC LIMIT 10");
        $comments = $this->plan('SELECT * FROM rr_comments FORCE INDEX (rr_comments_thread_index) '
            ."WHERE commentable_type = 'product' AND commentable_id = 1 AND status = 'approved' AND parent_id IS NULL ORDER BY helpful_score DESC, helpful_count DESC, created_at DESC, id DESC LIMIT 20");

        $this->assertStringContainsString('filesort', (string) $reviews->Extra);
        $this->assertStringContainsString('filesort', (string) $comments->Extra);
    }

    public function test_the_invitation_run_reads_the_open_invitations_only(): void
    {
        $now = now();
        $rows = [];

        // 300 invitations that were due long ago and are handled, 3 that are due and open.
        foreach (range(1, 303) as $n) {
            $open = $n > 300;
            $rows[] = [
                'user_id' => $n,
                'reviewable_type' => 'product',
                'reviewable_id' => 1,
                'source' => 'order',
                'due_at' => $now->copy()->subDays($open ? 1 : 30 + $n),
                'sent_at' => $open || $n % 2 === 0 ? null : $now,
                'completed_at' => $open || $n % 2 === 1 ? null : $now,
                'dismissed_at' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        DB::table('rr_review_invitations')->insert($rows);
        DB::statement('ANALYZE TABLE rr_review_invitations');

        $plan = $this->plan('SELECT * FROM rr_review_invitations '
            .'WHERE due_at <= NOW() AND sent_at IS NULL AND completed_at IS NULL AND dismissed_at IS NULL ORDER BY due_at, id LIMIT 500');

        $this->assertSame('rr_review_invitations_open_index', $plan->key);
        $this->assertStringNotContainsString('filesort', (string) $plan->Extra);
        $this->assertLessThanOrEqual(10, (int) $plan->rows, 'Only the open invitations are read, not the 300 handled ones.');
    }

    public function test_no_index_repeats_the_first_columns_of_another_index(): void
    {
        $redundant = [];

        foreach (Schema::getTables(DB::connection()->getDatabaseName()) as $table) {
            if (! str_starts_with($table['name'], 'rr_')) {
                continue;
            }

            $indexes = array_filter(Schema::getIndexes($table['name']), fn (array $index): bool => ($index['type'] ?? '') !== 'fulltext');

            foreach ($indexes as $index) {
                foreach ($indexes as $other) {
                    $prefix = array_slice($other['columns'], 0, count($index['columns']));

                    // A unique index is a constraint, so it stays even when a longer index starts with it.
                    if ($index['name'] !== $other['name'] && ! $index['unique'] && $prefix === $index['columns'] && count($other['columns']) > count($index['columns'])) {
                        $redundant[] = "{$table['name']}.{$index['name']} is a prefix of {$other['name']}";
                    }
                }
            }
        }

        $this->assertSame([], $redundant);
    }

    public function test_the_migration_upgrades_an_existing_database_rolls_back_and_runs_again(): void
    {
        $migration = $this->migration();
        DB::table('rr_votes')->insert(['votable_type' => 'rr_comment', 'votable_id' => 1, 'dimension' => 'agreement', 'value' => 1, 'user_id' => 7, 'voter_key' => 'u:7', 'created_at' => now(), 'updated_at' => now()]);

        $migration->down();

        $this->assertSame(['reviewable_type', 'reviewable_id', 'status', 'helpful_count'], $this->indexes('rr_reviews')['rr_reviews_subject_status_index']);
        $this->assertSame(['commentable_type', 'commentable_id', 'status', 'parent_id', 'helpful_score'], $this->indexes('rr_comments')['rr_comments_thread_index']);
        $this->assertSame(['due_at', 'sent_at'], $this->indexes('rr_review_invitations')['rr_review_invitations_due_index']);
        $this->assertSame(['user_id'], $this->indexes('rr_votes')['rr_votes_user_id_index']);
        $this->assertSame(['user_id'], $this->indexes('rr_poll_votes')['rr_poll_votes_user_id_index']);
        $this->assertSame(['user_id'], $this->indexes('rr_topic_ratings')['rr_topic_ratings_user_id_index']);
        $this->assertArrayNotHasKey('rr_reviews_subject_helpful_index', $this->indexes('rr_reviews'));
        $this->assertArrayNotHasKey('rr_comments_thread_helpful_index', $this->indexes('rr_comments'));
        $this->assertArrayNotHasKey('rr_review_invitations_open_index', $this->indexes('rr_review_invitations'));

        $migration->up();
        $migration->up();

        $this->assertSame(['reviewable_type', 'reviewable_id', 'status', 'helpful_count', 'created_at', 'id'], $this->indexes('rr_reviews')['rr_reviews_subject_helpful_index']);
        $this->assertSame(['commentable_type', 'commentable_id', 'status', 'parent_id', 'helpful_score', 'helpful_count', 'created_at', 'id'], $this->indexes('rr_comments')['rr_comments_thread_helpful_index']);
        $this->assertSame(['sent_at', 'completed_at', 'dismissed_at', 'due_at'], $this->indexes('rr_review_invitations')['rr_review_invitations_open_index']);

        foreach ([['rr_reviews', 'rr_reviews_subject_status_index'], ['rr_comments', 'rr_comments_thread_index'], ['rr_review_invitations', 'rr_review_invitations_due_index'], ['rr_votes', 'rr_votes_user_id_index'], ['rr_poll_votes', 'rr_poll_votes_user_id_index'], ['rr_topic_ratings', 'rr_topic_ratings_user_id_index']] as [$table, $index]) {
            $this->assertArrayNotHasKey($index, $this->indexes($table));
        }

        // `user_id` stays indexed through the composite indexes.
        $this->assertSame('rr_votes_user_item_index', $this->plan('SELECT id FROM rr_votes WHERE user_id = 7')->key);
        $this->assertSame(1, DB::table('rr_votes')->where('user_id', 7)->count(), 'Rows are untouched.');
    }

    public function test_a_database_without_the_composite_user_indexes_keeps_its_single_column_ones(): void
    {
        $this->migration()->down();
        Schema::table('rr_votes', fn ($table) => $table->dropIndex('rr_votes_user_item_index'));

        $this->migration()->up();

        $this->assertArrayHasKey('rr_votes_user_id_index', $this->indexes('rr_votes'));
        $this->assertArrayNotHasKey('rr_poll_votes_user_id_index', $this->indexes('rr_poll_votes'));
    }

    public function test_the_migrations_after_it_in_the_rollback_order_still_roll_back(): void
    {
        $paths = ['--path' => [realpath(__DIR__.'/../../database/migrations'), realpath(__DIR__.'/../database/migrations')], '--realpath' => true];

        // 000019 down to 000014: each older migration finds the indexes it created.
        $this->artisan('migrate:rollback', $paths + ['--step' => 6])->assertSuccessful();
        $this->assertFalse(Schema::hasColumn('rr_comments', 'pinned_at'));
        $this->assertArrayHasKey('rr_reviews_subject_status_index', $this->indexes('rr_reviews'));
        $this->assertArrayNotHasKey('rr_poll_votes_user_id_index', $this->indexes('rr_poll_votes'), '000017 removed its own index.');

        $this->artisan('migrate', $paths)->assertSuccessful();
        $this->assertArrayHasKey('rr_reviews_subject_helpful_index', $this->indexes('rr_reviews'));
        $this->assertArrayNotHasKey('rr_poll_votes_user_id_index', $this->indexes('rr_poll_votes'));
    }
}

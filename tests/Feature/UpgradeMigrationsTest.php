<?php

namespace RatingsAndReviews\Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RatingsAndReviews\Tests\CommittedTestCase;

/**
 * Migrations that change columns of databases migrated before the change (DDL, so on the
 * committed schema). A fresh install creates the new shape directly.
 */
class UpgradeMigrationsTest extends CommittedTestCase
{
    private function nullable(string $table, string $column): bool
    {
        foreach (Schema::getColumns($table) as $info) {
            if ($info['name'] === $column) {
                return (bool) $info['nullable'];
            }
        }

        $this->fail("No column {$table}.{$column}");
    }

    private function runMigration(string $file): void
    {
        $migration = require __DIR__.'/../../database/migrations/'.$file;
        $migration->up();
    }

    public function test_reporter_id_becomes_nullable_on_an_upgraded_database(): void
    {
        $this->assertTrue($this->nullable('rr_reports', 'reporter_id'), 'Fresh installs are nullable.');

        DB::statement('ALTER TABLE rr_reports MODIFY reporter_id BIGINT UNSIGNED NOT NULL');
        $this->assertFalse($this->nullable('rr_reports', 'reporter_id'));

        $this->runMigration('2026_11_02_000010_make_rr_reports_reporter_id_nullable.php');
        $this->assertTrue($this->nullable('rr_reports', 'reporter_id'));

        $this->runMigration('2026_11_02_000010_make_rr_reports_reporter_id_nullable.php');
        $this->assertTrue($this->nullable('rr_reports', 'reporter_id'), 'Running it again changes nothing.');
    }

    public function test_comment_user_id_becomes_nullable_on_an_upgraded_database(): void
    {
        $this->assertTrue($this->nullable('rr_comments', 'user_id'), 'Fresh installs are nullable.');

        DB::statement('ALTER TABLE rr_comments MODIFY user_id BIGINT UNSIGNED NOT NULL');
        Schema::table('rr_topic_ratings', fn ($table) => $table->dropIndex('rr_topic_ratings_erasure_index'));
        Schema::table('rr_comments', fn ($table) => $table->dropIndex('rr_comments_erasure_index'));
        Schema::table('rr_reviews', fn ($table) => $table->dropIndex('rr_reviews_erasure_index'));
        foreach (['rr_reviews', 'rr_comments', 'rr_topic_ratings'] as $table) {
            Schema::table($table, fn ($blueprint) => $blueprint->dropColumn('erasure_withheld_at'));
        }

        $this->runMigration('2026_11_02_000012_add_erasure_columns.php');

        $this->assertTrue($this->nullable('rr_comments', 'user_id'));
        $this->assertTrue($this->nullable('rr_reviews', 'erasure_withheld_at'));
    }

    public function test_the_participation_and_questionnaire_migrations_roll_back_and_upgrade_again(): void
    {
        $attribution = require __DIR__.'/../../database/migrations/2026_11_03_000014_add_attribution_and_pins.php';
        $questions = require __DIR__.'/../../database/migrations/2026_11_03_000015_create_rr_review_questions_and_follow_ups.php';

        $questions->down();
        $attribution->down();
        $this->assertFalse(Schema::hasColumn('rr_comments', 'pinned_at'));
        $this->assertFalse(Schema::hasTable('rr_review_questions'));
        $this->assertFalse(Schema::hasColumn('rr_reviews', 'follow_up_invited_at'));

        DB::table('rr_comments')->insert(['commentable_type' => 'product', 'commentable_id' => 1, 'section' => 'product', 'user_id' => 1, 'status' => 'approved', 'body_html' => '<p>Oud</p>', 'body_text' => 'Oud', 'published_at' => now(), 'created_at' => now(), 'updated_at' => now()]);

        $attribution->up();
        $questions->up();
        $attribution->up();
        $questions->up();

        foreach (['attribution', 'pinned_at', 'pinned_by'] as $column) {
            $this->assertTrue(Schema::hasColumn('rr_comments', $column), $column);
        }

        $this->assertTrue(Schema::hasColumn('rr_reviews', 'attribution'));
        $this->assertTrue(Schema::hasColumn('rr_reviews', 'follow_up_invited_at'));
        $this->assertTrue(Schema::hasColumn('rr_rating_aggregates', 'question_counts'));
        $this->assertTrue(Schema::hasTable('rr_review_answers'));
        $this->assertTrue(Schema::hasTable('rr_review_follow_ups'));
        $this->assertNull(DB::table('rr_comments')->value('pinned_at'), 'Existing comments stay unpinned.');
    }

    /** @return list<string> the names of the indexes whose first column is user_id */
    private function userIndexes(string $table): array
    {
        return array_values(array_map(fn (array $index): string => $index['name'], array_filter(Schema::getIndexes($table), fn (array $index): bool => ($index['columns'][0] ?? null) === 'user_id')));
    }

    public function test_the_uncounted_reason_migration_upgrades_rolls_back_and_runs_again(): void
    {
        $migration = require __DIR__.'/../../database/migrations/2026_11_03_000016_add_uncounted_reason_to_rr_reviews.php';
        $this->assertTrue($this->nullable('rr_reviews', 'uncounted_reason'), 'Fresh installs have the column.');

        $migration->down();
        $this->assertFalse(Schema::hasColumn('rr_reviews', 'uncounted_reason'));
        DB::table('rr_reviews')->insert(['reviewable_type' => 'product', 'reviewable_id' => 1, 'user_id' => 1, 'section' => 'product', 'status' => 'approved', 'rating' => 4, 'scale_max' => 5, 'body_html' => '', 'body_text' => '', 'counted' => false, 'created_at' => now(), 'updated_at' => now()]);

        $migration->up();
        $migration->up();
        $this->assertTrue($this->nullable('rr_reviews', 'uncounted_reason'));
        $this->assertSame(0, (int) DB::table('rr_reviews')->value('counted'), 'An existing uncounted review stays uncounted.');
        $this->assertNull(DB::table('rr_reviews')->value('uncounted_reason'));
    }

    public function test_the_vote_user_indexes_exist_and_are_added_to_an_upgraded_database(): void
    {
        $migration = require __DIR__.'/../../database/migrations/2026_11_03_000017_add_user_indexes_to_rr_votes.php';
        $this->assertSame(['rr_votes_user_id_index'], $this->userIndexes('rr_votes'));
        $this->assertSame(['rr_poll_votes_user_id_index'], $this->userIndexes('rr_poll_votes'));

        $migration->down();
        Schema::table('rr_votes', fn ($table) => $table->dropIndex('rr_votes_user_id_index'));
        $this->assertSame([], $this->userIndexes('rr_votes'));
        $this->assertSame([], $this->userIndexes('rr_poll_votes'));

        $migration->up();
        $migration->up();
        $this->assertSame(['rr_votes_user_id_index'], $this->userIndexes('rr_votes'));
        $this->assertSame(['rr_poll_votes_user_id_index'], $this->userIndexes('rr_poll_votes'));

        $plan = DB::select('EXPLAIN SELECT id FROM rr_poll_votes WHERE user_id = 1');
        $this->assertSame('rr_poll_votes_user_id_index', $plan[0]->possible_keys);
    }
}

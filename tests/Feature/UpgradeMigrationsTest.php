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
}

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Indexes on the voter's account for erasure (Erasure::purge) and fraud checks
 * (VoteService::recountForUsers): `rr_votes.user_id` (fresh installs since 000004 already
 * have `rr_votes_user_id_index`; only added when no index starts with user_id) and
 * `rr_poll_votes.user_id`. Idempotent.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! $this->hasUserIndex('rr_votes')) {
            Schema::table('rr_votes', function (Blueprint $table): void {
                $table->index('user_id', 'rr_votes_user_id_index');
            });
        }

        if (! $this->hasUserIndex('rr_poll_votes')) {
            Schema::table('rr_poll_votes', function (Blueprint $table): void {
                $table->index('user_id', 'rr_poll_votes_user_id_index');
            });
        }
    }

    public function down(): void
    {
        // rr_votes_user_id_index belongs to 000004 on fresh installs, so only the poll index goes.
        if (Schema::hasIndex('rr_poll_votes', 'rr_poll_votes_user_id_index')) {
            Schema::table('rr_poll_votes', function (Blueprint $table): void {
                $table->dropIndex('rr_poll_votes_user_id_index');
            });
        }
    }

    private function hasUserIndex(string $table): bool
    {
        foreach (Schema::getIndexes($table) as $index) {
            if (($index['columns'][0] ?? null) === 'user_id') {
                return true;
            }
        }

        return false;
    }
};

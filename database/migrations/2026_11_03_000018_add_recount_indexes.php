<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Composite indexes for the account-cluster recount and the per-section follow-up run. The
 * single-column indexes from 000017 do not cover the ordered DISTINCT queries of
 * VoteService::recountForUsers() and PollService::recountForUsers().
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rr_votes', function (Blueprint $table): void {
            $table->index(['user_id', 'votable_type', 'votable_id'], 'rr_votes_user_item_index');
        });

        Schema::table('rr_poll_votes', function (Blueprint $table): void {
            $table->index(['user_id', 'poll_id'], 'rr_poll_votes_user_poll_index');
        });

        Schema::table('rr_reviews', function (Blueprint $table): void {
            $table->index(['section', 'status', 'follow_up_invited_at', 'approved_at'], 'rr_reviews_section_follow_up_index');
        });
    }

    public function down(): void
    {
        Schema::table('rr_reviews', function (Blueprint $table): void {
            $table->dropIndex('rr_reviews_section_follow_up_index');
        });

        Schema::table('rr_poll_votes', function (Blueprint $table): void {
            $table->dropIndex('rr_poll_votes_user_poll_index');
        });

        Schema::table('rr_votes', function (Blueprint $table): void {
            $table->dropIndex('rr_votes_user_item_index');
        });
    }
};

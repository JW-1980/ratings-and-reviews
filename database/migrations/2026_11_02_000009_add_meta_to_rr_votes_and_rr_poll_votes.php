<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * rr_votes.meta and rr_poll_votes.meta: host-owned data stored with a vote, from the
 * `meta` of the RiskSignals verdict (for example the hashes a host's one-voice-per-person
 * rules compare later). Written in the same transaction as the vote; the package never
 * reads it. Null when the verdict has none.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rr_votes', function (Blueprint $table): void {
            $table->json('meta')->nullable()->after('uncounted_reason');
        });

        Schema::table('rr_poll_votes', function (Blueprint $table): void {
            $table->json('meta')->nullable()->after('uncounted_reason');
        });
    }

    public function down(): void
    {
        Schema::table('rr_poll_votes', function (Blueprint $table): void {
            $table->dropColumn('meta');
        });

        Schema::table('rr_votes', function (Blueprint $table): void {
            $table->dropColumn('meta');
        });
    }
};

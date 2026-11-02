<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rr_votes', function (Blueprint $table): void {
            $table->id();
            $table->string('votable_type', 64);
            $table->unsignedBigInteger('votable_id');
            $table->string('dimension', 16);
            $table->tinyInteger('value');
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('voter_key', 100);
            $table->string('network_hash', 64)->nullable();
            $table->string('device_hash', 64)->nullable();
            $table->boolean('counted')->default(true);
            $table->string('uncounted_reason', 64)->nullable();
            $table->timestamps();

            $table->unique(['votable_type', 'votable_id', 'dimension', 'voter_key'], 'rr_votes_voter_unique');
            $table->index(['votable_type', 'votable_id', 'dimension', 'counted'], 'rr_votes_count_index');
            $table->index(['voter_key', 'votable_type'], 'rr_votes_voter_index');
            $table->index('user_id');
        });

        Schema::create('rr_reports', function (Blueprint $table): void {
            $table->id();
            $table->string('reportable_type', 64);
            $table->unsignedBigInteger('reportable_id');
            $table->unsignedBigInteger('reporter_id')->nullable();
            $table->string('reason', 32);
            $table->text('details')->nullable();
            $table->json('snapshot')->nullable();
            $table->string('status', 16)->default('open');
            // 1 while open, NULL otherwise: the unique index below then allows one open report per reporter and item.
            $table->unsignedTinyInteger('open_marker')->nullable()->storedAs("CASE WHEN `status` = 'open' THEN 1 ELSE NULL END");
            $table->unsignedBigInteger('handled_by')->nullable();
            $table->timestamp('handled_at')->nullable();
            $table->text('resolution_note')->nullable();
            $table->timestamps();

            $table->unique(['reportable_type', 'reportable_id', 'reporter_id', 'open_marker'], 'rr_reports_one_open_unique');
            $table->index(['status', 'created_at'], 'rr_reports_status_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rr_reports');
        Schema::dropIfExists('rr_votes');
    }
};

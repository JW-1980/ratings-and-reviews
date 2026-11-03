<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tables used by the features built on top of the core: slow mode and locking, "new since
 * your last visit", polls, review invitations, uploads and stickers.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rr_discussion_states', function (Blueprint $table): void {
            $table->id();
            $table->string('commentable_type', 64);
            $table->unsignedBigInteger('commentable_id');
            $table->unsignedInteger('slow_mode_seconds')->nullable();
            $table->timestamp('locked_at')->nullable();
            $table->unsignedBigInteger('locked_by')->nullable();
            $table->timestamps();

            $table->unique(['commentable_type', 'commentable_id'], 'rr_discussion_states_subject_unique');
        });

        Schema::create('rr_read_markers', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->string('commentable_type', 64);
            $table->unsignedBigInteger('commentable_id');
            $table->timestamp('last_read_at');
            $table->timestamps();

            $table->unique(['user_id', 'commentable_type', 'commentable_id'], 'rr_read_markers_unique');
        });

        Schema::create('rr_polls', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('comment_id')->unique()->constrained('rr_comments')->cascadeOnDelete();
            $table->string('question', 255);
            $table->timestamp('closes_at')->nullable();
            $table->timestamps();
        });

        Schema::create('rr_poll_options', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('poll_id')->constrained('rr_polls')->cascadeOnDelete();
            $table->string('label', 120);
            $table->unsignedTinyInteger('sort_order')->default(0);
            $table->unsignedInteger('votes_count')->default(0);
            $table->timestamps();
        });

        Schema::create('rr_poll_votes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('poll_id')->constrained('rr_polls')->cascadeOnDelete();
            $table->foreignId('option_id')->constrained('rr_poll_options')->cascadeOnDelete();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('voter_key', 100);
            $table->string('network_hash', 64)->nullable();
            $table->string('device_hash', 64)->nullable();
            $table->boolean('counted')->default(true);
            $table->string('uncounted_reason', 64)->nullable();
            $table->timestamps();

            $table->unique(['poll_id', 'voter_key']);
        });

        Schema::create('rr_review_invitations', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->string('reviewable_type', 64);
            $table->unsignedBigInteger('reviewable_id');
            $table->string('source', 64);
            $table->timestamp('due_at');
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('dismissed_at')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'reviewable_type', 'reviewable_id'], 'rr_review_invitations_unique');
            $table->index(['due_at', 'sent_at'], 'rr_review_invitations_due_index');
        });

        Schema::create('rr_uploads', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->string('disk', 64);
            $table->string('path', 255)->unique();
            $table->string('alt_text', 255)->nullable();
            $table->string('attached_type', 64)->nullable();
            $table->unsignedBigInteger('attached_id')->nullable();
            $table->timestamp('attached_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'created_at']);
            $table->index(['attached_type', 'attached_id']);
        });

        Schema::create('rr_stickers', function (Blueprint $table): void {
            $table->id();
            $table->json('name');
            $table->string('path', 255)->unique();
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rr_stickers');
        Schema::dropIfExists('rr_uploads');
        Schema::dropIfExists('rr_review_invitations');
        Schema::dropIfExists('rr_poll_votes');
        Schema::dropIfExists('rr_poll_options');
        Schema::dropIfExists('rr_polls');
        Schema::dropIfExists('rr_read_markers');
        Schema::dropIfExists('rr_discussion_states');
    }
};

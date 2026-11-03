<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * rr_topic_ratings: a member's score for one rating topic of a subject without a review
 * (the section's `standalone_topic_ratings`, or imported, e.g. WelZoLekker's additive
 * stars). One row per subject, member and topic. Counted rows join the review-attached
 * topic ratings in the subject's topic averages, except where the same member also rated
 * the topic in a counted review of the subject (the review's score wins).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rr_topic_ratings', function (Blueprint $table): void {
            $table->id();
            $table->string('reviewable_type', 64);
            $table->unsignedBigInteger('reviewable_id');
            $table->unsignedBigInteger('user_id');
            $table->foreignId('topic_id')->constrained('rr_rating_topics')->cascadeOnDelete();
            $table->unsignedTinyInteger('value');
            $table->unsignedTinyInteger('scale_max')->default(5);
            $table->boolean('counted')->default(true);
            $table->string('uncounted_reason', 64)->nullable();
            $table->string('ip_hash', 64)->nullable();
            $table->string('network_hash', 64)->nullable();
            $table->string('device_hash', 64)->nullable();
            $table->timestamps();

            $table->unique(['reviewable_type', 'reviewable_id', 'user_id', 'topic_id'], 'rr_topic_ratings_unique');
            $table->index(['reviewable_type', 'reviewable_id', 'counted', 'topic_id'], 'rr_topic_ratings_subject_index');
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rr_topic_ratings');
    }
};

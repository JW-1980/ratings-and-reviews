<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rr_reviews', function (Blueprint $table): void {
            $table->id();
            $table->string('reviewable_type', 64);
            $table->unsignedBigInteger('reviewable_id');
            $table->unsignedBigInteger('user_id');
            $table->string('status', 16)->default('pending');

            $table->unsignedTinyInteger('rating');
            $table->unsignedTinyInteger('scale_max')->default(5);
            $table->string('title', 255)->nullable();
            $table->mediumText('body_html');
            $table->text('body_text');
            $table->json('pros')->nullable();
            $table->json('cons')->nullable();

            $table->json('facets')->nullable();
            $table->unsignedInteger('price_cents')->nullable();
            $table->char('price_currency', 3)->nullable();
            $table->string('compared_type', 64)->nullable();
            $table->unsignedBigInteger('compared_id')->nullable();
            $table->string('comparison', 8)->nullable();
            $table->unsignedTinyInteger('quality_score')->nullable();

            $table->unsignedInteger('helpful_count')->default(0);
            $table->unsignedInteger('flags_count')->default(0);
            $table->string('ip_hash', 64)->nullable();
            $table->string('network_hash', 64)->nullable();
            $table->string('device_hash', 64)->nullable();
            $table->boolean('counted')->default(true);

            $table->timestamp('approved_at')->nullable();
            $table->timestamp('edited_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['reviewable_type', 'reviewable_id', 'user_id'], 'rr_reviews_subject_user_unique');
            $table->index(['reviewable_type', 'reviewable_id', 'status', 'helpful_count'], 'rr_reviews_subject_status_index');
            $table->index(['status', 'created_at'], 'rr_reviews_status_created_index');
            $table->index(['user_id', 'created_at'], 'rr_reviews_user_created_index');

            // FULLTEXT exists on MySQL/MariaDB only; elsewhere SearchService falls back to LIKE.
            if (in_array(Schema::getConnection()->getDriverName(), ['mysql', 'mariadb'], true)) {
                $table->fullText(['title', 'body_text'], 'rr_reviews_fulltext');
            }
        });

        Schema::create('rr_review_topic_ratings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('review_id')->constrained('rr_reviews')->cascadeOnDelete();
            $table->foreignId('topic_id')->constrained('rr_rating_topics')->cascadeOnDelete();
            $table->unsignedTinyInteger('value');
            $table->unsignedTinyInteger('scale_max')->default(5);
            $table->timestamps();

            $table->unique(['review_id', 'topic_id']);
        });

        Schema::create('rr_rating_aggregates', function (Blueprint $table): void {
            $table->id();
            $table->string('reviewable_type', 64);
            $table->unsignedBigInteger('reviewable_id');
            $table->string('scope', 64);
            $table->unsignedInteger('review_count')->default(0);
            $table->double('normalized_sum')->default(0);
            $table->double('normalized_sum_squares')->default(0);
            $table->json('histogram')->nullable();
            $table->json('topics')->nullable();
            $table->timestamp('last_review_at')->nullable();
            $table->timestamp('computed_at')->nullable();
            $table->timestamps();

            $table->unique(['reviewable_type', 'reviewable_id'], 'rr_rating_aggregates_subject_unique');
            $table->index(['scope', 'review_count'], 'rr_rating_aggregates_scope_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rr_rating_aggregates');
        Schema::dropIfExists('rr_review_topic_ratings');
        Schema::dropIfExists('rr_reviews');
    }
};

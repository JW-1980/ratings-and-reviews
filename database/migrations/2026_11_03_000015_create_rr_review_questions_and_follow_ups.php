<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Review questions (a questionnaire per scope and category, with inheritance by key), the
 * answers per review, and follow-ups ("what do you think after a month?").
 *
 * - rr_review_questions: `scope`, `category_key` (null: the whole scope), `key`, `type`
 *   (scale, choice, multi), `label`/`low_label`/`high_label` (json nl/en), `options`
 *   (json list of {value, label: {nl, en}}), `is_required`, `is_active`, `sort_order`.
 *   Unique per scope, category and key.
 * - rr_review_answers: one row per review, question and chosen option (`option_key`; ''
 *   for a scale answer, whose number is `value`).
 * - rr_review_follow_ups: one per review, by its author, with the rating then and now.
 * - rr_reviews.follow_up_invited_at: when ReviewFollowUpDue was fired for the review.
 * - rr_rating_aggregates.question_counts / follow_up_stats: computed by the aggregate job.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('rr_review_questions')) {
            Schema::create('rr_review_questions', function (Blueprint $table): void {
                $table->id();
                $table->string('scope', 64);
                $table->string('category_key', 64)->nullable();
                $table->string('key', 64);
                $table->string('type', 16)->default('scale');
                $table->json('label');
                $table->json('low_label')->nullable();
                $table->json('high_label')->nullable();
                $table->json('options')->nullable();
                $table->boolean('is_required')->default(false);
                $table->boolean('is_active')->default(true);
                $table->unsignedSmallInteger('sort_order')->default(0);
                $table->timestamps();

                $table->unique(['scope', 'category_key', 'key'], 'rr_review_questions_key_unique');
                $table->index(['scope', 'is_active', 'category_key'], 'rr_review_questions_scope_index');
            });
        }

        if (! Schema::hasTable('rr_review_answers')) {
            Schema::create('rr_review_answers', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('review_id')->constrained('rr_reviews')->cascadeOnDelete();
                $table->foreignId('question_id')->constrained('rr_review_questions')->cascadeOnDelete();
                $table->string('option_key', 64)->default('');
                $table->unsignedTinyInteger('value')->nullable();
                $table->timestamps();

                $table->unique(['review_id', 'question_id', 'option_key'], 'rr_review_answers_unique');
                $table->index(['question_id', 'option_key', 'review_id'], 'rr_review_answers_question_index');
            });
        }

        if (! Schema::hasTable('rr_review_follow_ups')) {
            Schema::create('rr_review_follow_ups', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('review_id')->unique('rr_review_follow_ups_review_unique')->constrained('rr_reviews')->cascadeOnDelete();
                $table->unsignedBigInteger('user_id');
                $table->unsignedTinyInteger('rating');
                $table->unsignedTinyInteger('scale_max')->default(5);
                $table->unsignedTinyInteger('initial_rating');
                $table->unsignedTinyInteger('initial_scale_max')->default(5);
                $table->text('body_text')->nullable();
                $table->timestamps();

                $table->index('user_id', 'rr_review_follow_ups_user_index');
            });
        }

        Schema::table('rr_reviews', function (Blueprint $table): void {
            if (! Schema::hasColumn('rr_reviews', 'follow_up_invited_at')) {
                $table->timestamp('follow_up_invited_at')->nullable()->after('approved_at');
                $table->index(['status', 'follow_up_invited_at', 'approved_at'], 'rr_reviews_follow_up_due_index');
            }
        });

        Schema::table('rr_rating_aggregates', function (Blueprint $table): void {
            if (! Schema::hasColumn('rr_rating_aggregates', 'question_counts')) {
                $table->json('question_counts')->nullable()->after('comparisons');
                $table->json('follow_up_stats')->nullable()->after('question_counts');
            }
        });
    }

    public function down(): void
    {
        Schema::table('rr_rating_aggregates', function (Blueprint $table): void {
            $table->dropColumn(['question_counts', 'follow_up_stats']);
        });

        Schema::table('rr_reviews', function (Blueprint $table): void {
            $table->dropIndex('rr_reviews_follow_up_due_index');
            $table->dropColumn('follow_up_invited_at');
        });

        Schema::dropIfExists('rr_review_follow_ups');
        Schema::dropIfExists('rr_review_answers');
        Schema::dropIfExists('rr_review_questions');
    }
};

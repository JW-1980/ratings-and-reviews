<?php

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RatingsAndReviews\Contracts\Reviewable;

/**
 * The section of a review and of a reported item, stored with the row, so the moderation
 * lists can be narrowed to the sections a moderator may moderate (AbilityResolver with a
 * ModerationScope) without loading every subject.
 *
 * - rr_reviews.section: the subject's reviewSectionKey() when the review was written; set by
 *   ReviewService on every submission and edit. Existing rows are filled here from their
 *   subjects (through the morph map); a subject that cannot be loaded leaves null.
 * - rr_reports.section: the section of the reported review or comment (null for a host
 *   item without one), set by ReportService; existing rows are filled from rr_reviews and
 *   rr_comments.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rr_reviews', function (Blueprint $table): void {
            $table->string('section', 64)->nullable()->after('user_id');
            $table->index(['status', 'section', 'created_at'], 'rr_reviews_moderation_index');
        });

        Schema::table('rr_reports', function (Blueprint $table): void {
            $table->string('section', 64)->nullable()->after('reportable_id');
            $table->index(['status', 'reportable_type', 'section'], 'rr_reports_moderation_index');
        });

        Schema::table('rr_comments', function (Blueprint $table): void {
            $table->index(['status', 'section', 'created_at'], 'rr_comments_moderation_index');
        });

        $this->fillReviewSections();
        $this->fillReportSections();
    }

    public function down(): void
    {
        Schema::table('rr_comments', function (Blueprint $table): void {
            $table->dropIndex('rr_comments_moderation_index');
        });

        Schema::table('rr_reports', function (Blueprint $table): void {
            $table->dropIndex('rr_reports_moderation_index');
            $table->dropColumn('section');
        });

        Schema::table('rr_reviews', function (Blueprint $table): void {
            $table->dropIndex('rr_reviews_moderation_index');
            $table->dropColumn('section');
        });
    }

    /** The section of the reported package review or comment. */
    private function fillReportSections(): void
    {
        foreach (['rr_review' => 'rr_reviews', 'rr_comment' => 'rr_comments'] as $type => $table) {
            DB::table('rr_reports')
                ->where('reportable_type', $type)
                ->whereNull('section')
                ->update(['section' => DB::table($table)->select('section')->whereColumn($table.'.id', 'rr_reports.reportable_id')->limit(1)]);
        }
    }

    /**
     * Every subject type through the morph map, in chunks of 500 subjects. A type that is no
     * longer mapped or whose model fails to load is reported and left null; the next
     * submission or edit of such a review stores its section.
     */
    private function fillReviewSections(): void
    {
        $types = DB::table('rr_reviews')->whereNull('section')->distinct()->pluck('reviewable_type');

        foreach ($types as $type) {
            $class = Relation::getMorphedModel((string) $type);

            if ($class === null || ! is_subclass_of($class, Model::class) || ! is_subclass_of($class, Reviewable::class)) {
                continue;
            }

            try {
                // The ids first: updating while paging over "section is null" would skip rows.
                $ids = DB::table('rr_reviews')->where('reviewable_type', $type)->whereNull('section')->distinct()->pluck('reviewable_id')->all();

                foreach (array_chunk($ids, 500) as $chunk) {
                    foreach ($class::query()->withoutGlobalScopes()->whereKey($chunk)->get() as $subject) {
                        DB::table('rr_reviews')->where('reviewable_type', $type)->where('reviewable_id', $subject->getKey())
                            ->update(['section' => $subject->reviewSectionKey()]);
                    }
                }
            } catch (Throwable $exception) {
                report($exception);
            }
        }
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Wilson lower bound (95%, z = 1.96) of helpful votes among helpful + unhelpful votes;
     * 0 without votes. Stored so "most helpful" reads straight from the thread index.
     */
    private const WILSON = 'CASE WHEN (`helpful_count` + `unhelpful_count`) = 0 THEN 0 ELSE '
        .'((`helpful_count` / (`helpful_count` + `unhelpful_count`)) + 1.9208 / (`helpful_count` + `unhelpful_count`) '
        .'- 1.96 * SQRT(((`helpful_count` / (`helpful_count` + `unhelpful_count`)) * (1 - `helpful_count` / (`helpful_count` + `unhelpful_count`)) '
        .'+ 0.9604 / (`helpful_count` + `unhelpful_count`)) / (`helpful_count` + `unhelpful_count`))) '
        .'/ (1 + 3.8416 / (`helpful_count` + `unhelpful_count`)) END';

    public function up(): void
    {
        // MySQL/MariaDB: FULLTEXT and the generated Wilson column. Other engines (a host's
        // SQLite test suite) get no FULLTEXT (SearchService uses LIKE there) and a plain
        // helpful_score column that the Comment model computes on save.
        $mysql = in_array(Schema::getConnection()->getDriverName(), ['mysql', 'mariadb'], true);

        Schema::create('rr_comments', function (Blueprint $table) use ($mysql): void {
            $table->id();
            $table->string('commentable_type', 64);
            $table->unsignedBigInteger('commentable_id');
            $table->string('section', 64);
            $table->foreignId('parent_id')->nullable()->constrained('rr_comments')->cascadeOnDelete();
            $table->foreignId('root_id')->nullable()->constrained('rr_comments')->cascadeOnDelete();
            $table->foreignId('in_reply_to_id')->nullable()->constrained('rr_comments')->nullOnDelete();
            $table->unsignedTinyInteger('depth')->default(0);
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('status', 16)->default('pending');

            $table->mediumText('body_html');
            $table->text('body_text');
            $table->foreignId('quoted_comment_id')->nullable()->constrained('rr_comments')->nullOnDelete();
            $table->text('quote_text')->nullable();

            $table->unsignedInteger('agree_count')->default(0);
            $table->unsignedInteger('disagree_count')->default(0);
            $table->unsignedInteger('helpful_count')->default(0);
            $table->unsignedInteger('unhelpful_count')->default(0);
            $table->unsignedInteger('needs_source_count')->default(0);
            if ($mysql) {
                $table->double('helpful_score')->storedAs(self::WILSON);
            } else {
                $table->double('helpful_score')->default(0);
            }

            $table->decimal('controversy_score', 14, 6)->default(0);
            $table->unsignedInteger('flags_count')->default(0);

            $table->boolean('is_edited')->default(false);
            $table->timestamp('edited_at')->nullable();
            $table->timestamp('author_removed_at')->nullable();
            $table->boolean('held_as_evidence')->default(false);
            $table->timestamp('publish_at')->nullable();
            $table->timestamp('published_at')->nullable();

            $table->string('ip_hash', 64)->nullable();
            $table->string('network_hash', 64)->nullable();
            $table->string('device_hash', 64)->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['commentable_type', 'commentable_id', 'status', 'parent_id', 'helpful_score'], 'rr_comments_thread_index');
            $table->index(['root_id', 'status', 'depth'], 'rr_comments_root_index');
            $table->index(['status', 'created_at'], 'rr_comments_status_created_index');
            $table->index(['user_id', 'created_at'], 'rr_comments_user_created_index');

            if ($mysql) {
                $table->fullText('body_text', 'rr_comments_fulltext');
            }
        });

        Schema::create('rr_comment_revisions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('comment_id')->constrained('rr_comments')->cascadeOnDelete();
            $table->unsignedInteger('revision');
            $table->mediumText('body_html');
            $table->text('body_text');
            $table->unsignedBigInteger('edited_by')->nullable();
            $table->timestamp('edited_at');

            $table->unique(['comment_id', 'revision']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rr_comment_revisions');
        Schema::dropIfExists('rr_comments');
    }
};

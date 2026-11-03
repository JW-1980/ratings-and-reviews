<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Columns for the package's own upload endpoint, review photos and managed stickers:
 *
 * - rr_uploads.role: `inline` (an image in the text) or `photo` (a review photo), set when the
 *   upload is attached; `position` orders the photos of a review. `mime_type`, `size`, `width`
 *   and `height` describe the stored file. Existing rows keep null (treated as inline).
 * - rr_uploads (attached_id, created_at): rr:prune-uploads finds unattached uploads past their
 *   grace period with it.
 * - rr_stickers.disk: the disk of a sticker image uploaded through admin/stickers (null: the
 *   file is managed by the host, as before).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rr_uploads', function (Blueprint $table): void {
            $table->string('role', 16)->nullable()->after('attached_at');
            $table->unsignedSmallInteger('position')->default(0)->after('role');
            $table->string('mime_type', 64)->nullable()->after('path');
            $table->unsignedInteger('size')->nullable()->after('mime_type');
            $table->unsignedSmallInteger('width')->nullable()->after('size');
            $table->unsignedSmallInteger('height')->nullable()->after('width');

            $table->index(['attached_id', 'created_at'], 'rr_uploads_unattached_index');
        });

        Schema::table('rr_stickers', function (Blueprint $table): void {
            $table->string('disk', 64)->nullable()->after('path');
        });
    }

    public function down(): void
    {
        Schema::table('rr_stickers', function (Blueprint $table): void {
            $table->dropColumn('disk');
        });

        Schema::table('rr_uploads', function (Blueprint $table): void {
            $table->dropIndex('rr_uploads_unattached_index');
            $table->dropColumn(['role', 'position', 'mime_type', 'size', 'width', 'height']);
        });
    }
};

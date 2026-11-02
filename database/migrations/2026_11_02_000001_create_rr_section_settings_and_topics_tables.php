<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rr_section_settings', function (Blueprint $table): void {
            $table->id();
            $table->string('section', 64)->unique();
            $table->json('settings');
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();
        });

        Schema::create('rr_rating_topics', function (Blueprint $table): void {
            $table->id();
            $table->string('scope', 64);
            $table->string('category_key', 64)->nullable();
            $table->foreignId('parent_id')->nullable()->constrained('rr_rating_topics')->nullOnDelete();
            $table->json('name');
            $table->unsignedTinyInteger('scale_max')->default(5);
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['scope', 'category_key', 'is_active'], 'rr_rating_topics_scope_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rr_rating_topics');
        Schema::dropIfExists('rr_section_settings');
    }
};

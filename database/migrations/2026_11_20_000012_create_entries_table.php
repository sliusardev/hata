<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('entry_group_id')->constrained('entry_groups')->cascadeOnDelete();
            $table->string('type', 40);
            $table->string('locale', 12);
            $table->foreignId('parent_id')->nullable()->constrained('entries')->nullOnDelete();
            $table->foreignId('author_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('title');
            $table->string('slug');
            $table->string('path');
            $table->longText('body')->nullable();
            $table->text('excerpt')->nullable();
            $table->string('seo_title')->nullable();
            $table->text('seo_description')->nullable();
            $table->foreignId('image_id')->nullable()->constrained('media')->nullOnDelete();
            $table->json('fields')->nullable();
            $table->string('status', 20)->default('draft');
            // Not a timestamp: MySQL's ends in 2038 and starts in 1970, and a page may be dated outside that.
            $table->dateTime('published_at')->nullable();
            $table->text('search_index')->nullable();
            $table->softDeletes();
            $table->timestamps();

            $table->unique(['entry_group_id', 'locale']);
            $table->unique(['type', 'locale', 'path']);
            // `deleted_at` third: the list's counters and its page count filter on it, and the public lookups
            // (live, published, by date) keep their equality prefix.
            $table->index(['type', 'locale', 'deleted_at', 'status', 'published_at']);
            // The trash prune looks for what is due across every type.
            $table->index('deleted_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('entries');
    }
};

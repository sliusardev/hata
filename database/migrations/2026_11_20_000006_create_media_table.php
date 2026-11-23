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
        Schema::create('media', function (Blueprint $table) {
            $table->id();
            $table->string('key', 26)->unique();
            $table->foreignId('folder_id')->nullable()->constrained('media_folders')->nullOnDelete();
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('disk');
            $table->string('path');
            $table->string('original_path')->nullable();
            $table->string('thumb_path')->nullable();
            $table->string('name')->unique();
            $table->string('kind', 20)->index();
            $table->string('mime');
            $table->unsignedBigInteger('size');
            $table->unsignedInteger('width')->nullable();
            $table->unsignedInteger('height')->nullable();
            $table->unsignedBigInteger('original_size')->nullable();
            $table->string('uploaded_extension', 10)->nullable();
            $table->string('optimized_with')->nullable();
            $table->string('optimize_failure', 20)->nullable();
            $table->boolean('animated')->default(false);
            $table->json('edits')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->string('title')->nullable();
            $table->json('alt')->nullable();
            $table->text('search_index')->nullable();
            $table->timestamps();

            $table->index('created_at');
            // A folder's files, newest first, and the per-folder counts are read on every library request. MySQL
            // indexes the foreign key by itself, SQLite does not, and neither covers the date order.
            $table->index(['folder_id', 'created_at', 'id'], 'media_folder_listing_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('media');
    }
};

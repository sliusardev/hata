<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A folder's files, newest first, and the per-folder counts are read on every library request. MySQL indexes
     * the foreign key by itself, SQLite does not, and neither covers the date order.
     */
    public function up(): void
    {
        Schema::table('media', function (Blueprint $table) {
            $table->index(['folder_id', 'created_at', 'id'], 'media_folder_listing_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('media', function (Blueprint $table) {
            $table->dropIndex('media_folder_listing_index');
        });
    }
};

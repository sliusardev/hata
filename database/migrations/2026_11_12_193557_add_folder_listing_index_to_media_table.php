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
     * MySQL needs an index that starts with folder_id for the foreign key and may have dropped its own once this
     * one existed, so make sure another one is there before dropping it.
     */
    public function down(): void
    {
        $hasOtherIndex = collect(Schema::getIndexes('media'))->contains(
            fn (array $index): bool => $index['name'] !== 'media_folder_listing_index' && ($index['columns'][0] ?? null) === 'folder_id',
        );

        Schema::table('media', function (Blueprint $table) use ($hasOtherIndex) {
            if (! $hasOtherIndex && Schema::getConnection()->getDriverName() !== 'sqlite') {
                $table->index('folder_id', 'media_folder_id_foreign');
            }

            $table->dropIndex('media_folder_listing_index');
        });
    }
};

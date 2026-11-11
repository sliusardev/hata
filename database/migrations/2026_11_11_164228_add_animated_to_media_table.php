<?php

use App\Support\Media\MediaTypes;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('media', function (Blueprint $table) {
            $table->boolean('animated')->default(false)->after('optimize_failure');
        });

        // Only formats that can be animated need their file read; a missing or unreadable file stays false.
        DB::table('media')
            ->where('kind', 'image')
            ->whereIn('mime', ['image/gif', 'image/webp', 'image/png'])
            ->chunkById(100, function ($rows): void {
                foreach ($rows as $row) {
                    try {
                        $disk = Storage::disk($row->disk);

                        if ($disk->exists($row->path) && MediaTypes::isAnimated((string) $disk->get($row->path), $row->mime)) {
                            DB::table('media')->where('id', $row->id)->update(['animated' => true]);
                        }
                    } catch (Throwable) {
                        // Unreadable file: not animated.
                    }
                }
            });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('media', function (Blueprint $table) {
            $table->dropColumn('animated');
        });
    }
};

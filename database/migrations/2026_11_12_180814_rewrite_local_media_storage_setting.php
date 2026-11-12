<?php

use App\Models\Setting;
use App\Support\Settings\SiteSettings;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * "This server" used to be stored as `local`, the private disk. New uploads of raster images now go to the
     * `public` disk, so the stored value becomes `public`. A stored `s3` or an absent row stays as it is.
     */
    public function up(): void
    {
        $setting = Setting::query()->where('key', 'media_storage')->first();

        if ($setting === null || $setting->value !== 'local') {
            return;
        }

        $setting->update(['value' => 'public']);

        Cache::forget(SiteSettings::CACHE_KEY);
    }

    /**
     * Reverse the migrations. The old value is not restored: `public` is valid for the new code only.
     */
    public function down(): void {}
};

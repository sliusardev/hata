<?php

use App\Actions\Media\UploadMedia;
use App\Actions\Settings\SetLogo;
use App\Models\MediaFolder;
use App\Models\Setting;
use App\Support\Settings\SiteSettings;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Env;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Logos uploaded before the media library lived as paths ("branding/...") on the logo disk.
     * Each one becomes a library file in the top-level "Logo" folder, and the setting then holds its
     * media id, exactly as SetLogo stores it. A logo that can't be imported is dropped (the default
     * mark shows instead) and the migration never fails the deploy.
     */
    public function up(): void
    {
        // The `admin.logo.disk` config key no longer exists, so the old disk is read from the env here.
        // With a cached config the env isn't loaded and the old default `local` is used, which is the usual value.
        $disk = (string) Env::get('ADMIN_LOGO_DISK', 'local');

        foreach (['light', 'dark'] as $variant) {
            $setting = Setting::query()->where('key', "logo_{$variant}")->first();

            // An id, an empty value or a missing row: nothing to import (also makes a second run a no-op).
            if ($setting === null || ! is_string($setting->value) || $setting->value === '' || ctype_digit($setting->value)) {
                continue;
            }

            try {
                $this->importFile($disk, $setting->value, $variant);
            } catch (Throwable $e) {
                report($e);

                $setting->delete();
            }
        }

        Cache::forget(SiteSettings::CACHE_KEY);
    }

    /**
     * @throws Throwable
     */
    private function importFile(string $disk, string $path, string $variant): void
    {
        $storage = Storage::disk($disk);
        $bytes = $storage->get($path);

        if ($bytes === null) {
            throw new RuntimeException("Old logo [{$path}] was not found on disk [{$disk}].");
        }

        $temporary = tempnam(sys_get_temp_dir(), 'logo');

        try {
            file_put_contents($temporary, $bytes);

            $media = app(UploadMedia::class)->import(
                new UploadedFile($temporary, basename($path), null, null, true),
                MediaFolder::query()->firstOrCreate(['name' => 'Logo', 'parent_id' => null]),
            );
        } finally {
            @unlink($temporary);
        }

        app(SetLogo::class)->assign($variant, $media->id);

        try {
            $storage->delete($path);
        } catch (Throwable $e) {
            report($e);
        }
    }

    /**
     * Reverse the migrations.
     *
     * Intentionally empty: the imported files stay in the library and the old files are gone.
     */
    public function down(): void {}
};

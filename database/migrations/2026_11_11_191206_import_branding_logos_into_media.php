<?php

use App\Actions\Media\UploadMedia;
use App\Actions\Settings\SetLogo;
use App\Models\Media;
use App\Models\MediaFolder;
use App\Models\Setting;
use App\Support\Settings\SiteSettings;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Env;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Logos uploaded before the media library lived as paths ("branding/...") on the logo disk.
     * Each one becomes a library file named "logo-{variant}.{ext}" in the top-level "Logo" folder, and the
     * setting then holds its media id, exactly as SetLogo stores it. A logo that can't be imported is dropped
     * (the default mark shows instead) and the migration never fails the deploy. The old file is deleted only
     * once the new one is stored and the setting committed; if storing the setting fails, the imported library
     * file is removed again and the old file stays where it was, so nothing is lost and nothing is orphaned.
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

                try {
                    $setting->delete();
                } catch (Throwable $e) {
                    report($e);
                }
            }
        }

        try {
            Cache::forget(SiteSettings::CACHE_KEY);
        } catch (Throwable $e) {
            report($e);
        }
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

        // Under storage/app rather than the system temp directory, which open_basedir may forbid on shared hosting.
        $temporary = tempnam(storage_path('app'), 'logo');

        if ($temporary === false) {
            throw new RuntimeException('Could not create a temporary file for the old logo.');
        }

        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        try {
            file_put_contents($temporary, $bytes);

            $media = app(UploadMedia::class)->import(
                new UploadedFile($temporary, "logo-{$variant}".($extension === '' ? '' : ".{$extension}"), null, null, true),
                MediaFolder::query()->firstOrCreate(['name' => 'Logo', 'parent_id' => null]),
            );
        } finally {
            @unlink($temporary);
        }

        try {
            app(SetLogo::class)->assign($variant, $media->id);
        } catch (Throwable $e) {
            $this->discard($media);

            throw $e;
        }

        // After the commit: on SQLite the migration runs in a transaction, and a rollback must find the old file.
        DB::afterCommit(function () use ($storage, $path): void {
            try {
                $storage->delete($path);
            } catch (Throwable $e) {
                report($e);
            }
        });
    }

    /**
     * Remove an imported library file again (row and stored files) when its logo setting couldn't be saved.
     */
    private function discard(Media $media): void
    {
        try {
            $media->delete();
            Storage::disk($media->disk)->delete(array_values(array_filter(
                [$media->path, $media->thumb_path],
                fn (?string $file): bool => $file !== null && $file !== '',
            )));

            // The original lives on the originals disk: the private one when the file is on the public disk.
            if ($media->original_path !== null && $media->original_path !== '') {
                Storage::disk($media->originalsDisk())->delete($media->original_path);
            }
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

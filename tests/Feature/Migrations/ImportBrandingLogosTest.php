<?php

use App\Models\Media;
use App\Models\MediaFolder;
use App\Models\MediaUsage;
use App\Models\Setting;
use App\Support\Settings\SiteSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

function runLogoImport(): void
{
    (require glob(database_path('migrations/*_import_branding_logos_into_media.php'))[0])->up();
}

beforeEach(function () {
    Storage::fake('local');
    Storage::fake('media');
    config(['media.disk' => 'media']);
});

it('imports a branding logo into the Logo folder and keeps the setting and usage as SetLogo would', function () {
    Storage::disk('local')->put('branding/logo-light.png', makeImage('png'));
    Setting::create(['key' => 'logo_light', 'value' => 'branding/logo-light.png']);
    Cache::forever(SiteSettings::CACHE_KEY, ['stale' => true]);

    runLogoImport();

    $media = Media::query()->sole();
    $folder = MediaFolder::query()->where('name', 'Logo')->whereNull('parent_id')->sole();

    expect($media->folder_id)->toBe($folder->id)
        ->and($media->uploaded_by)->toBeNull()
        ->and($media->kind->value)->toBe('image')
        ->and(Setting::query()->where('key', 'logo_light')->value('value'))->toBe($media->id)
        ->and(MediaUsage::query()->where('field', 'logo_light')->where('media_id', $media->id)->count())->toBe(1)
        ->and(Storage::disk('local')->exists('branding/logo-light.png'))->toBeFalse()
        ->and(Cache::has(SiteSettings::CACHE_KEY))->toBeFalse();
});

it('reuses an existing top-level Logo folder and imports both slots', function () {
    $folder = MediaFolder::factory()->create(['name' => 'Logo', 'parent_id' => null]);
    Storage::disk('local')->put('branding/a.png', makeImage('png'));
    Storage::disk('local')->put('branding/b.jpg', makeImage('jpg'));
    Setting::create(['key' => 'logo_light', 'value' => 'branding/a.png']);
    Setting::create(['key' => 'logo_dark', 'value' => 'branding/b.jpg']);

    runLogoImport();

    expect(MediaFolder::query()->count())->toBe(1)
        ->and(Media::query()->where('folder_id', $folder->id)->count())->toBe(2)
        ->and(MediaUsage::query()->count())->toBe(2);
});

it('removes the setting of a missing file and carries on', function () {
    Setting::create(['key' => 'logo_light', 'value' => 'branding/gone.png']);
    Storage::disk('local')->put('branding/b.png', makeImage('png'));
    Setting::create(['key' => 'logo_dark', 'value' => 'branding/b.png']);

    runLogoImport();

    expect(Setting::query()->where('key', 'logo_light')->exists())->toBeFalse()
        ->and(Setting::query()->where('key', 'logo_dark')->value('value'))->toBe(Media::query()->sole()->id);
});

it('removes the setting of a file that is not an image', function () {
    Storage::disk('local')->put('branding/notes.png', 'just some text');
    Setting::create(['key' => 'logo_dark', 'value' => 'branding/notes.png']);

    runLogoImport();

    expect(Setting::query()->where('key', 'logo_dark')->exists())->toBeFalse()
        ->and(Media::query()->count())->toBe(0)
        ->and(MediaUsage::query()->count())->toBe(0);
});

it('changes nothing when run twice', function () {
    Storage::disk('local')->put('branding/a.png', makeImage('png'));
    Setting::create(['key' => 'logo_light', 'value' => 'branding/a.png']);

    runLogoImport();
    $id = Setting::query()->where('key', 'logo_light')->value('value');
    runLogoImport();

    expect(Media::query()->count())->toBe(1)
        ->and(Setting::query()->where('key', 'logo_light')->value('value'))->toBe($id)
        ->and(MediaUsage::query()->count())->toBe(1);
});

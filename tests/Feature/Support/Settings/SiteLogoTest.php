<?php

use App\Models\Media;
use App\Support\Settings\SiteLogo;
use App\Support\Settings\SiteSettings;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('local');
});

function storedLogo(string $name = 'logo.webp', string $mime = 'image/webp'): Media
{
    $media = Media::factory()->image()->create(['name' => $name, 'mime' => $mime]);
    Storage::disk($media->disk)->put($media->path, 'file');

    return $media;
}

it('uses the variant\'s own file, else the other variant\'s', function () {
    $dark = storedLogo();
    app(SiteSettings::class)->put(['logo_dark' => $dark->id]);

    expect(SiteLogo::media('light')?->id)->toBe($dark->id)
        ->and(SiteLogo::media('dark')?->id)->toBe($dark->id)
        ->and(SiteLogo::own('light'))->toBeNull()
        ->and(SiteLogo::url('light'))->toBe($dark->url())
        ->and(SiteLogo::isCustom())->toBeTrue();

    $light = storedLogo('light.svg', 'image/svg+xml');
    app(SiteSettings::class)->put(['logo_light' => $light->id]);

    expect(SiteLogo::media('light')?->id)->toBe($light->id)
        ->and(SiteLogo::media('dark')?->id)->toBe($dark->id);
});

it('treats a missing file on disk as empty and falls back to the other variant', function () {
    $light = storedLogo();
    $dark = storedLogo('dark.png', 'image/png');
    app(SiteSettings::class)->put(['logo_light' => $light->id, 'logo_dark' => $dark->id]);
    Storage::disk('local')->delete($light->path);
    SiteLogo::flush();

    expect(SiteLogo::media('light')?->id)->toBe($dark->id)
        ->and(SiteLogo::media('dark')?->id)->toBe($dark->id);
});

it('treats a missing row, a missing file and a non-logo file as empty', function () {
    $gif = storedLogo('spinner.gif', 'image/gif');
    app(SiteSettings::class)->put(['logo_light' => $gif->id, 'logo_dark' => 999999]);

    expect(SiteLogo::media('light'))->toBeNull()
        ->and(SiteLogo::media('dark'))->toBeNull()
        ->and(SiteLogo::url('light'))->toBeNull()
        ->and(SiteLogo::isCustom())->toBeFalse();
});

it('renders the default mark, not an image with a dead URL, when both logos are gone', function () {
    $light = storedLogo();
    app(SiteSettings::class)->put(['logo_light' => $light->id]);
    Storage::disk('local')->delete($light->path);
    SiteLogo::flush();

    expect(view('components.site-logo')->render())->not->toContain('<img');
});

it('trusts the row on a cloud disk without asking the disk whether the file exists', function () {
    $disk = fakeCloudDisk('s3');
    $logo = Media::factory()->image()->create(['disk' => 's3', 'name' => 'logo.webp']);
    app(SiteSettings::class)->put(['logo_light' => $logo->id]);

    // Nothing was ever written: a local disk would treat the logo as missing.
    expect($disk->exists($logo->path))->toBeFalse()
        ->and(SiteLogo::own('light')?->id)->toBe($logo->id);
});

<?php

use App\Models\Setting;
use App\Support\Settings\SiteSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;

uses(RefreshDatabase::class);

function runStorageRewrite(): void
{
    (require glob(database_path('migrations/*_rewrite_local_media_storage_setting.php'))[0])->up();
}

it('turns a stored local storage into public and forgets the cached settings', function () {
    Setting::create(['key' => 'media_storage', 'value' => 'local']);
    Cache::forever(SiteSettings::CACHE_KEY, ['stale' => true]);

    runStorageRewrite();

    expect(Setting::query()->where('key', 'media_storage')->value('value'))->toBe('public')
        ->and(Cache::has(SiteSettings::CACHE_KEY))->toBeFalse();
});

it('leaves s3 alone', function () {
    Setting::create(['key' => 'media_storage', 'value' => 's3']);

    runStorageRewrite();

    expect(Setting::query()->where('key', 'media_storage')->value('value'))->toBe('s3');
});

it('does nothing without a stored storage', function () {
    runStorageRewrite();

    expect(Setting::query()->where('key', 'media_storage')->exists())->toBeFalse();
});

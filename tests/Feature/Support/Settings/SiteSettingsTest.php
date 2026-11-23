<?php

use Hata\Models\Setting;
use Hata\Support\Settings\SiteSettings;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Jobs\SyncJob;
use Illuminate\Support\Facades\Cache;

test('removing a setting brings back the value from the config file', function () {
    $original = config('app.name');
    $settings = app(SiteSettings::class);

    $settings->put(['site_name' => 'Hata']);
    expect(config('app.name'))->toBe('Hata');

    $settings->put(['site_name' => null]);
    expect(config('app.name'))->toBe($original)
        ->and($settings->has('site_name'))->toBeFalse();
});

test('it ignores keys that are not settings', function () {
    app(SiteSettings::class)->put(['app_key' => 'x']);

    expect(app(SiteSettings::class)->stored())->toBe([]);
});

test('without a stored row the media config keeps its default', function () {
    app(SiteSettings::class)->apply();

    expect(config('media.optimize.quality'))->toBe(80);
});

test('stored media settings reach config as real bools and ints', function () {
    app(SiteSettings::class)->put(['media_strip_exif' => false, 'media_max_side' => 0, 'media_quality' => 65]);

    expect(config('media.optimize.strip_exif'))->toBeFalse()
        ->and(config('media.optimize.max_side'))->toBeNull()
        ->and(config('media.optimize.quality'))->toBe(65);
});

test('a queued job sees settings changed after the worker booted, and removed ones revert', function () {
    $settings = app(SiteSettings::class);
    $settings->put(['media_keep_originals' => false, 'media_quality' => 65]);

    // Another process (the admin page) changes the settings; this "worker" booted with the old ones.
    Setting::query()->where('key', 'media_keep_originals')->delete();
    Setting::query()->updateOrCreate(['key' => 'media_quality'], ['value' => 70]);
    Cache::forget(SiteSettings::CACHE_KEY);

    expect(config('media.optimize.quality'))->toBe(65);

    event(new JobProcessing('database', new SyncJob(app(), '{}', 'database', 'default')));

    expect(config('media.optimize.quality'))->toBe(70)
        ->and(config('media.optimize.keep_originals'))->toBeTrue();
});

test('a job on the sync connection keeps the settings of the request it runs in', function () {
    app(SiteSettings::class)->put(['media_quality' => 65, 'default_locale' => 'en']);
    app()->setLocale('uk');

    Setting::query()->updateOrCreate(['key' => 'media_quality'], ['value' => 70]);
    Cache::forget(SiteSettings::CACHE_KEY);

    dispatch(function () {
        Cache::put('seen', config('media.optimize.quality'));
    })->onConnection('sync');

    expect(Cache::get('seen'))->toBe(65)
        ->and(app()->getLocale())->toBe('uk');
});

test('stored-only values never reach config and are removed when set to null', function () {
    $settings = app(SiteSettings::class);
    $config = config()->all();

    $settings->put(['media_direct_state' => 'ok', 'media_direct_url' => 'https://example.test/storage']);
    $settings->putStoredOnly(['media_direct_checked_at' => '2026-11-12T10:00:00+00:00', 'site_name' => 'Not stored here']);
    $settings->apply();

    expect(config()->all())->toBe($config)
        ->and($settings->value('media_direct_state'))->toBe('ok')
        ->and($settings->value('media_direct_checked_at'))->toBe('2026-11-12T10:00:00+00:00')
        ->and($settings->has('site_name'))->toBeFalse();

    $settings->put(['media_direct_state' => null]);
    $settings->putStoredOnly(['media_direct_checked_at' => null]);

    expect($settings->has('media_direct_state'))->toBeFalse()
        ->and($settings->has('media_direct_checked_at'))->toBeFalse()
        ->and(Setting::query()->whereIn('key', ['media_direct_state', 'media_direct_checked_at'])->exists())->toBeFalse()
        ->and($settings->value('media_direct_url'))->toBe('https://example.test/storage');
});

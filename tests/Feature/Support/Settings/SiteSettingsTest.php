<?php

use App\Support\Settings\SiteSettings;

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

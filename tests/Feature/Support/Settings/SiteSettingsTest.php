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

<?php

use Hata\Database\Seeders\HataSeeder;
use Hata\Models\Entry;
use Hata\Support\Localization\ContentLocales;
use Hata\Support\Settings\SiteSettings;

beforeEach(function () {
    app(SiteSettings::class)->put(['default_locale' => 'uk']);
});

it('stores the content languages on first seeding', function () {
    $this->seed(HataSeeder::class);

    $settings = app(SiteSettings::class);

    expect($settings->value('content_locales'))->toBe([['code' => 'uk', 'active' => true]])
        ->and($settings->value('content_default_locale'))->toBe('uk');
});

it('keeps a stored content language list', function () {
    app(SiteSettings::class)->put([
        'content_locales' => [['code' => 'uk', 'active' => true], ['code' => 'en', 'active' => true]],
        'content_default_locale' => 'en',
    ]);

    $this->seed(HataSeeder::class);
    $this->seed(HataSeeder::class);

    $settings = app(SiteSettings::class);

    expect($settings->value('content_locales'))->toBe([['code' => 'uk', 'active' => true], ['code' => 'en', 'active' => true]])
        ->and($settings->value('content_default_locale'))->toBe('en');
});

it('does not move the site language when the interface default changes', function () {
    $this->seed(HataSeeder::class);

    Entry::factory()->page()->published()->inLocale('uk')->create(['slug' => 'test-page', 'path' => 'test-page']);

    app(SiteSettings::class)->put(['default_locale' => 'en']);

    expect(app(ContentLocales::class)->default()->code)->toBe('uk');

    $this->get('/test-page')->assertOk();
});

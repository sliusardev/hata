<?php

use Hata\Models\Setting;
use Hata\Support\Settings\SiteSettings;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

function settingsRegistry(): SiteSettings
{
    return app(SiteSettings::class);
}

it('defines every key it used to', function () {
    $paths = [
        'site_name' => 'app.name',
        'default_locale' => 'app.locale',
        'timezone' => 'hata.admin.timezone',
        'registration_enabled' => 'hata.admin.registration_enabled',
        'default_role' => 'hata.admin.default_role',
        'mail_mailer' => 'mail.default',
        'mail_host' => 'mail.mailers.smtp.host',
        'mail_port' => 'mail.mailers.smtp.port',
        'mail_scheme' => 'mail.mailers.smtp.scheme',
        'mail_username' => 'mail.mailers.smtp.username',
        'mail_password' => 'mail.mailers.smtp.password',
        'mail_from_address' => 'mail.from.address',
        'mail_from_name' => 'mail.from.name',
        'logo_light' => 'hata.admin.logo.light',
        'logo_dark' => 'hata.admin.logo.dark',
        'color_scheme' => 'hata.admin.colors.scheme',
        'color_accent' => 'hata.admin.colors.accent',
        'color_gray' => 'hata.admin.colors.gray',
        'color_tint_sidebar' => 'hata.admin.colors.tint_sidebar',
        'media_optimize' => 'hata.media.optimize.enabled',
        'media_max_side' => 'hata.media.optimize.max_side',
        'media_quality' => 'hata.media.optimize.quality',
        'media_format' => 'hata.media.optimize.format',
        'media_strip_exif' => 'hata.media.optimize.strip_exif',
        'media_client_resize' => 'hata.media.optimize.client_resize',
        'media_keep_originals' => 'hata.media.optimize.keep_originals',
        'media_max_image_mb' => 'hata.media.limits.image_mb',
        'media_max_document_mb' => 'hata.media.limits.document_mb',
        'media_storage' => 'hata.media.disk',
        'media_s3_endpoint' => 'filesystems.disks.s3.endpoint',
        'media_s3_region' => 'filesystems.disks.s3.region',
        'media_s3_bucket' => 'filesystems.disks.s3.bucket',
        'media_s3_key' => 'filesystems.disks.s3.key',
        'media_s3_secret' => 'filesystems.disks.s3.secret',
        'media_s3_path_style' => 'filesystems.disks.s3.use_path_style_endpoint',
        'media_s3_public_url' => 'filesystems.disks.s3.url',
        'media_s3_prefix' => 'filesystems.disks.s3.root',
        'content_locales' => 'hata.content.locales',
        'content_default_locale' => 'hata.content.default_locale',
        'content_disabled_types' => 'hata.content.disabled_types',
    ];
    $storedOnly = [
        'media_direct_state',
        'media_direct_url',
        'media_direct_checked_at',
        'media_direct_attempted_at',
        'media_direct_attempted_url',
    ];
    $casts = [
        'registration_enabled' => 'bool',
        'color_tint_sidebar' => 'bool',
        'mail_port' => 'int',
        'logo_light' => 'int',
        'logo_dark' => 'int',
        'media_optimize' => 'bool',
        'media_max_side' => 'int',
        'media_quality' => 'int',
        'media_strip_exif' => 'bool',
        'media_client_resize' => 'bool',
        'media_keep_originals' => 'bool',
        'media_max_image_mb' => 'int',
        'media_max_document_mb' => 'int',
        'media_s3_path_style' => 'bool',
    ];

    foreach ($paths as $key => $path) {
        $definition = settingsRegistry()->definition($key);

        expect($definition)->not->toBeNull()
            ->and($definition->config)->toBe($path, $key)
            ->and($definition->cast)->toBe($casts[$key] ?? null, $key)
            ->and($definition->encrypted)->toBe(in_array($key, ['mail_password', 'media_s3_secret'], true), $key);
    }

    foreach ($storedOnly as $key) {
        expect(settingsRegistry()->definition($key)?->config)->toBeNull()
            ->and(settingsRegistry()->isDefined($key))->toBeTrue();
    }

    expect(count($paths) + count($storedOnly))->toBe(45);
});

it('stores, applies and casts a defined key', function () {
    settingsRegistry()
        ->define('shop_currency', 'shop.currency')
        ->define('shop_tags', 'shop.tags', 'array');

    settingsRegistry()->put(['shop_currency' => 'UAH', 'shop_tags' => ['a', 'b']]);

    expect(config('shop.currency'))->toBe('UAH')
        ->and(config('shop.tags'))->toBe(['a', 'b'])
        ->and(Setting::query()->where('key', 'shop_tags')->value('value'))->toBe('["a","b"]');

    config(['shop.tags' => null]);
    Cache::forget(SiteSettings::CACHE_KEY);
    settingsRegistry()->apply();

    expect(config('shop.tags'))->toBe(['a', 'b']);
});

it('applies a key defined after apply at once', function () {
    Setting::query()->create(['key' => 'shop_currency', 'value' => 'UAH']);
    Cache::forget(SiteSettings::CACHE_KEY);
    settingsRegistry()->apply();

    expect(config('shop.currency'))->toBeNull();

    settingsRegistry()->define('shop_currency', 'shop.currency');

    expect(config('shop.currency'))->toBe('UAH');
});

it('throws for an undefined key while debugging', function () {
    expect(fn () => settingsRegistry()->put(['nope' => 1]))->toThrow(LogicException::class, 'nope');
});

it('skips an undefined key in production and logs it', function () {
    app()->detectEnvironment(fn (): string => 'production');
    config(['app.debug' => false]);
    Log::spy();

    settingsRegistry()->put(['nope_prod' => 1]);

    expect(Setting::query()->where('key', 'nope_prod')->exists())->toBeFalse();
    Log::shouldHaveReceived('warning')->once()->withArgs(fn (string $message): bool => str_contains($message, 'nope_prod'));
});

it('refuses putStoredOnly for a key with a config path', function () {
    settingsRegistry()->putStoredOnly(['site_name' => 'X', 'media_direct_state' => 'ok']);

    expect(Setting::query()->where('key', 'site_name')->exists())->toBeFalse()
        ->and(settingsRegistry()->value('media_direct_state'))->toBe('ok');
});

it('ignores stored rows with an unknown key', function () {
    Setting::query()->create(['key' => 'gone_module_key', 'value' => 'x']);
    Cache::forget(SiteSettings::CACHE_KEY);

    settingsRegistry()->apply();

    expect(settingsRegistry()->stored())->toHaveKey('gone_module_key');
});

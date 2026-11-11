<?php

namespace App\Support\Settings;

use App\Models\Setting;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;

/**
 * Site settings edited in the admin panel.
 *
 * Each setting overrides one config value, so the rest of the code keeps reading config().
 * A setting that is not stored leaves the config value (usually from .env) untouched.
 */
class SiteSettings
{
    public const string CACHE_KEY = 'site_settings';

    /**
     * Setting key => config path it overrides.
     *
     * @var array<string, string>
     */
    public const array CONFIG_PATHS = [
        'site_name' => 'app.name',
        'default_locale' => 'app.locale',
        'timezone' => 'admin.timezone',
        'registration_enabled' => 'admin.registration_enabled',
        'default_role' => 'admin.default_role',
        'mail_mailer' => 'mail.default',
        'mail_host' => 'mail.mailers.smtp.host',
        'mail_port' => 'mail.mailers.smtp.port',
        'mail_scheme' => 'mail.mailers.smtp.scheme',
        'mail_username' => 'mail.mailers.smtp.username',
        'mail_password' => 'mail.mailers.smtp.password',
        'mail_from_address' => 'mail.from.address',
        'mail_from_name' => 'mail.from.name',
        'logo_light' => 'admin.logo.light',
        'logo_dark' => 'admin.logo.dark',
        'color_scheme' => 'admin.colors.scheme',
        'color_accent' => 'admin.colors.accent',
        'color_gray' => 'admin.colors.gray',
        'color_tint_sidebar' => 'admin.colors.tint_sidebar',
        'media_optimize' => 'media.optimize.enabled',
        'media_max_side' => 'media.optimize.max_side',
        'media_quality' => 'media.optimize.quality',
        'media_format' => 'media.optimize.format',
        'media_strip_exif' => 'media.optimize.strip_exif',
        'media_client_resize' => 'media.optimize.client_resize',
        'media_keep_originals' => 'media.optimize.keep_originals',
        'media_max_image_mb' => 'media.limits.image_mb',
        'media_max_document_mb' => 'media.limits.document_mb',
        'media_storage' => 'media.disk',
        'media_s3_endpoint' => 'filesystems.disks.s3.endpoint',
        'media_s3_region' => 'filesystems.disks.s3.region',
        'media_s3_bucket' => 'filesystems.disks.s3.bucket',
        'media_s3_key' => 'filesystems.disks.s3.key',
        'media_s3_secret' => 'filesystems.disks.s3.secret',
        'media_s3_path_style' => 'filesystems.disks.s3.use_path_style_endpoint',
        'media_s3_public_url' => 'filesystems.disks.s3.url',
        'media_s3_prefix' => 'filesystems.disks.s3.root',
    ];

    /**
     * Settings that must reach config as a given type (the code checks with is_int()/is_bool()).
     *
     * @var array<string, 'int'|'bool'>
     */
    public const array CASTS = [
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

    /**
     * "Do not scale" is stored as 0, because a null value would remove the setting
     * and bring back the default from config/media.php. In config it is null.
     */
    public const string NO_MAX_SIDE = 'media_max_side';

    /**
     * Settings stored encrypted with the application key.
     *
     * @var list<string>
     */
    public const array ENCRYPTED = ['mail_password', 'media_s3_secret'];

    /**
     * Config values before the first override, restored when a setting is removed.
     *
     * @var array<string, mixed>
     */
    private array $defaults = [];

    /**
     * Stored values, encrypted ones still encrypted. Empty while the settings table does not exist yet.
     *
     * @return array<string, mixed>
     */
    public function stored(): array
    {
        try {
            /** @var array<string, mixed> */
            return Cache::rememberForever(
                self::CACHE_KEY,
                fn (): array => Setting::query()->pluck('value', 'key')->all(),
            );
        } catch (QueryException) {
            return [];
        }
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->stored());
    }

    /**
     * Write stored settings over the config.
     */
    public function apply(): void
    {
        SiteLogo::flush();

        foreach ($this->defaults as $path => $value) {
            config([$path => $value]);
        }

        foreach ($this->stored() as $key => $value) {
            $path = self::CONFIG_PATHS[$key] ?? null;

            if ($path === null || $value === null) {
                continue;
            }

            if (in_array($key, self::ENCRYPTED, true)) {
                try {
                    $value = Crypt::decryptString((string) $value);
                } catch (DecryptException) {
                    continue;
                }
            }

            if (! array_key_exists($path, $this->defaults)) {
                $this->defaults[$path] = config($path);
            }

            config([$path => $this->cast($key, $value)]);
        }

        // The s3 disk is resolved once and keeps its config, so a changed setting needs a fresh one.
        Storage::forgetDisk('s3');
    }

    private function cast(string $key, mixed $value): mixed
    {
        $value = match (self::CASTS[$key] ?? null) {
            'int' => is_numeric($value) ? (int) $value : $value,
            'bool' => filter_var($value, FILTER_VALIDATE_BOOLEAN),
            default => $value,
        };

        return $key === self::NO_MAX_SIDE && $value === 0 ? null : $value;
    }

    /**
     * Store the given settings and apply them right away. A null value removes the setting,
     * so the config value from .env takes over again.
     *
     * @param  array<string, mixed>  $values
     */
    public function put(array $values): void
    {
        DB::transaction(function () use ($values): void {
            foreach ($values as $key => $value) {
                if (! array_key_exists($key, self::CONFIG_PATHS)) {
                    continue;
                }

                if ($value === null) {
                    Setting::query()->where('key', $key)->delete();

                    continue;
                }

                if (in_array($key, self::ENCRYPTED, true)) {
                    $value = Crypt::encryptString((string) $value);
                }

                Setting::query()->updateOrCreate(['key' => $key], ['value' => $value]);
            }
        });

        // Inside an enclosing transaction (a library delete) the cache and the config change only once it commits.
        DB::afterCommit(function (): void {
            Cache::forget(self::CACHE_KEY);

            $this->apply();

            Mail::forgetMailers();
        });
    }
}

<?php

namespace App\Support\Settings;

use App\Models\Setting;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

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
    ];

    /**
     * Settings stored encrypted with the application key.
     *
     * @var list<string>
     */
    public const array ENCRYPTED = ['mail_password'];

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

            config([$path => $value]);
        }
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

        Cache::forget(self::CACHE_KEY);

        $this->apply();

        Mail::forgetMailers();
    }
}

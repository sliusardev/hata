<?php

namespace Hata\Support\Settings;

use Closure;
use Hata\Models\Setting;
use Hata\Support\Seo\Sitemap;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use LogicException;

/**
 * Site settings edited in the admin panel.
 *
 * Each setting overrides one config value, so the rest of the code keeps reading config().
 * A setting that is not stored leaves the config value (usually from .env) untouched.
 */
class SiteSettings
{
    private static int $suspended = 0;

    /**
     * Problems already logged in this process.
     *
     * @var array<string, true>
     */
    private static array $warned = [];

    public const string CACHE_KEY = 'site_settings';

    /**
     * "Do not scale" is stored as 0, because a null value would remove the setting
     * and bring back the default from config/hata.php. In config it is null.
     */
    public const string NO_MAX_SIDE = 'media_max_side';

    /**
     * The defined keys. A site or module adds its own with define().
     *
     * @var array<string, SettingDefinition>
     */
    private array $definitions = [];

    /**
     * Whether apply() has run in this process, so a key defined later is applied at once.
     */
    private bool $applied = false;

    /**
     * Config values before the first override, restored when a setting is removed.
     *
     * @var array<string, mixed>
     */
    private array $defaults = [];

    /**
     * The stored values as read once in this process (request, command or job), so the many callers of a request
     * (every Media::url() asks DirectServing, which reads them) cost one cache read in all. Cleared by apply() and
     * after every write.
     *
     * @var array<string, mixed>|null
     */
    private ?array $memo = null;

    /**
     * `app.locale` as the config files give it, read before any request could change it:
     * Application::setLocale() writes the current request's language into that key.
     */
    private readonly string $configuredLocale;

    public function __construct()
    {
        $this->configuredLocale = (string) config('app.locale');
    }

    /**
     * Define a setting key: the config path it overrides (null for a value the app stores and reads itself),
     * the type it reaches config as and whether it is stored encrypted. A key defined after the settings were
     * applied is applied at once.
     *
     * @param  'int'|'bool'|'array'|null  $cast
     */
    public function define(string $key, ?string $config = null, ?string $cast = null, bool $encrypted = false): static
    {
        $this->definitions[$key] = new SettingDefinition($key, $config, $cast, $encrypted);

        if ($this->applied && $config !== null && ! self::buildingConfigCache()) {
            $this->memo = null;
            $this->applyKey($this->definitions[$key]);
        }

        return $this;
    }

    public function definition(string $key): ?SettingDefinition
    {
        return $this->definitions[$key] ?? null;
    }

    public function isDefined(string $key): bool
    {
        return isset($this->definitions[$key]);
    }

    /**
     * Stored values, encrypted ones still encrypted. Empty while the settings table does not exist yet.
     *
     * @return array<string, mixed>
     */
    public function stored(): array
    {
        if ($this->memo !== null) {
            return $this->memo;
        }

        try {
            /** @var array<string, mixed> */
            return $this->memo = Cache::rememberForever(
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
     * The stored value of a key, decrypted and decoded by its definition (an encrypted key that cannot be decrypted
     * reads as null). Other casts are applied only where the value reaches config.
     */
    public function value(string $key): mixed
    {
        $value = $this->stored()[$key] ?? null;
        $definition = $this->definition($key);

        if ($value === null || $definition === null) {
            return $value;
        }

        if ($definition->encrypted) {
            try {
                $value = Crypt::decryptString((string) $value);
            } catch (DecryptException) {
                return null;
            }
        }

        if ($definition->cast === 'array' && is_string($value)) {
            return json_decode($value, true) ?? [];
        }

        return $value;
    }

    /**
     * What is written to the table for a value: JSON for an array key, then encrypted for an encrypted one.
     */
    private function encode(SettingDefinition $definition, mixed $value): mixed
    {
        if ($definition->cast === 'array') {
            $value = json_encode($value, JSON_THROW_ON_ERROR);
        }

        if ($definition->encrypted) {
            $value = Crypt::encryptString((string) $value);
        }

        return $value;
    }

    /**
     * Run the callback with apply() switched off.
     *
     * @template TReturn
     *
     * @param  Closure(): TReturn  $callback
     * @return TReturn
     */
    public static function withoutApplying(Closure $callback): mixed
    {
        self::suspend();

        try {
            return $callback();
        } finally {
            self::resume();
        }
    }

    /**
     * Switch apply() off until resume(); calls nest.
     */
    public static function suspend(): void
    {
        self::$suspended++;
    }

    public static function resume(): void
    {
        self::$suspended = max(0, self::$suspended - 1);
    }

    /**
     * Whether this process is building the config cache. Laravel boots a fresh application to collect the
     * config; if the stored settings were applied to it, they would be frozen into bootstrap/cache/config.php:
     * a setting removed later would keep its old value, and the decrypted SMTP password and S3 secret would be
     * written to that file. AppServiceProvider suspends applying while `config:cache` or `optimize` runs (by
     * the console's command events, so options, abbreviations and Artisan::call() are covered), and the
     * maintenance page does the same around "Rebuild caches".
     */
    public static function buildingConfigCache(): bool
    {
        return self::$suspended > 0;
    }

    /**
     * Write stored settings over the config. Nothing while the config cache is being built.
     */
    public function apply(): void
    {
        if (self::buildingConfigCache()) {
            return;
        }

        $this->memo = null;
        SiteLogo::flush();
        $s3Before = config('filesystems.disks.s3');

        foreach ($this->defaults as $path => $value) {
            config([$path => $value]);
        }

        $this->applied = true;

        foreach ($this->definitions as $definition) {
            if ($definition->config !== null) {
                $this->applyKey($definition);
            }
        }

        // The site's default interface language, where no request's own language can overwrite it.
        $defaultLocale = $this->stored()['default_locale'] ?? null;
        config(['hata.content.interface_default' => is_string($defaultLocale) && $defaultLocale !== '' ? $defaultLocale : $this->configuredLocale]);

        // The s3 disk is resolved once and keeps its config, so a changed setting needs a fresh one. Only then: the CLI
        // and queue workers apply the settings before every batch and keep the resolved client otherwise.
        if (config('filesystems.disks.s3') !== $s3Before) {
            Storage::forgetDisk('s3');
        }
    }

    /**
     * Write one stored setting over its config path. A key stored for no definition, or not stored, does nothing.
     */
    private function applyKey(SettingDefinition $definition): void
    {
        $value = $this->stored()[$definition->key] ?? null;

        if ($value === null || $definition->config === null) {
            return;
        }

        if ($definition->encrypted) {
            try {
                $value = Crypt::decryptString((string) $value);
            } catch (DecryptException) {
                return;
            }
        }

        if (! array_key_exists($definition->config, $this->defaults)) {
            $this->defaults[$definition->config] = config($definition->config);
        }

        config([$definition->config => $this->cast($definition, $value)]);
    }

    private function cast(SettingDefinition $definition, mixed $value): mixed
    {
        $value = match ($definition->cast) {
            'int' => is_numeric($value) ? (int) $value : $value,
            'bool' => filter_var($value, FILTER_VALIDATE_BOOLEAN),
            'array' => is_string($value) ? (json_decode($value, true) ?? []) : $value,
            default => $value,
        };

        return $definition->key === self::NO_MAX_SIDE && $value === 0 ? null : $value;
    }

    /**
     * A mistake in a setting key: thrown in the local and testing environments, else logged once and skipped.
     */
    private function problem(string $problem): void
    {
        $message = "Site settings: {$problem}.";

        if (app()->environment('local', 'testing')) {
            throw new LogicException($message);
        }

        if (isset(self::$warned[$message])) {
            return;
        }

        self::$warned[$message] = true;

        Log::warning($message);
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
            $sitemapChanged = false;

            foreach ($values as $key => $value) {
                $definition = $this->definition($key);

                if ($definition === null) {
                    $this->problem("the key [{$key}] is not defined, so it is not stored");

                    continue;
                }

                if ($value === null) {
                    $changed = Setting::query()->where('key', $key)->delete() > 0;
                } else {
                    $setting = Setting::query()->updateOrCreate(['key' => $key], ['value' => $this->encode($definition, $value)]);
                    $changed = $setting->wasRecentlyCreated || $setting->wasChanged();
                }

                $sitemapChanged = $sitemapChanged || ($changed && in_array($key, Sitemap::SETTINGS, true));
            }

            // A removed row leaves no `updated_at` behind, so the sitemap's Last-Modified reads this marker instead.
            if ($sitemapChanged) {
                Setting::query()->updateOrCreate(['key' => Sitemap::CHANGED_SETTING], ['value' => (string) now()->getTimestamp()]);
            }
        });

        // Inside an enclosing transaction (a library delete) the cache and the config change only once it commits.
        DB::afterCommit(function (): void {
            Cache::forget(self::CACHE_KEY);
            $this->memo = null;

            $this->apply();

            Mail::forgetMailers();
        });
    }

    /**
     * Store values the app keeps for itself (defined without a config path: probe results), which reach no config. Only the settings
     * cache and this process's copy are refreshed: no apply(), so logos, mailers and the s3 disk stay as they are. A
     * null value removes the key; other keys are ignored.
     *
     * @param  array<string, mixed>  $values
     */
    public function putStoredOnly(array $values): void
    {
        $values = array_filter(
            $values,
            fn (string $key): bool => $this->definition($key)?->config === null && $this->isDefined($key),
            ARRAY_FILTER_USE_KEY,
        );

        if ($values === []) {
            return;
        }

        DB::transaction(function () use ($values): void {
            foreach ($values as $key => $value) {
                $value === null
                    ? Setting::query()->where('key', $key)->delete()
                    : Setting::query()->updateOrCreate(['key' => $key], ['value' => $this->encode($this->definitions[$key], $value)]);
            }
        });

        DB::afterCommit(function (): void {
            Cache::forget(self::CACHE_KEY);
            $this->memo = null;
        });
    }
}

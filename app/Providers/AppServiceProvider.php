<?php

namespace App\Providers;

use App\Http\Middleware\SetContentLocale;
use App\Models\User;
use App\Support\Localization\ContentLocales;
use App\Support\Localization\LanguageCatalog;
use App\Support\Media\DirectServing;
use App\Support\Permissions\PermissionRegistry;
use App\Support\Settings\SiteSettings;
use App\Support\Translation\VendorTranslations;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Console\Events\CommandFinished;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use Livewire\Livewire;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(
            PermissionRegistry::class,
            fn (): PermissionRegistry => (new PermissionRegistry)->registerMany(config('permissions.groups', [])),
        );

        $this->app->singleton(SiteSettings::class);
        $this->app->singleton(DirectServing::class);
        $this->app->singleton(ContentLocales::class);

        $this->registerLocalizedRoutes();
    }

    /**
     * `Route::localized(fn () => ...)` registers public routes twice: as they are (the default content
     * language) and under `/{locale}` with the names prefixed `localized.`. The prefix matches any code of
     * the language catalogue, which is code, so the routes never depend on the site settings and can be
     * cached; SetContentLocale decides per request whether that language is on.
     */
    private function registerLocalizedRoutes(): void
    {
        Route::macro('localized', function (Closure $routes): void {
            // The prefixed copy first: a wildcard route of the default language ("{slug}/{child}") would
            // otherwise take "/de/about" for itself.
            Route::middleware(SetContentLocale::class)
                ->prefix('{locale}')
                ->name(ContentLocales::ROUTE_PREFIX)
                ->where(['locale' => app(LanguageCatalog::class)->pattern()])
                ->group($routes);

            Route::middleware(SetContentLocale::class)->group($routes);
        });
    }

    /**
     * The commands that write bootstrap/cache/config.php.
     *
     * @var list<string>
     */
    private const array CONFIG_CACHE_COMMANDS = ['config:cache', 'optimize'];

    /**
     * While `config:cache` or `optimize` runs, the stored site settings are not applied, so the fresh application
     * Laravel boots to collect the config holds the `.env`-level values only (see
     * SiteSettings::buildingConfigCache()). The console's events carry the resolved command name, however it
     * was typed or called.
     */
    private function keepSettingsOutOfTheConfigCache(): void
    {
        Event::listen(function (CommandStarting $event): void {
            if (in_array($event->command, self::CONFIG_CACHE_COMMANDS, true)) {
                SiteSettings::suspend();
            }
        });

        Event::listen(function (CommandFinished $event): void {
            if (in_array($event->command, self::CONFIG_CACHE_COMMANDS, true)) {
                SiteSettings::resume();
            }
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->keepSettingsOutOfTheConfigCache();
        $this->app->make(SiteSettings::class)->apply();
        $this->configureQueue();
        $this->configureMedia();
        $this->configureDefaults();
        $this->configureTranslations();
        $this->configureAuthorization();

        // A Livewire component on a public page keeps the page's content language on its update requests.
        Livewire::addPersistentMiddleware(SetContentLocale::class);
    }

    /**
     * Re-apply the site settings before every queued job. A worker boots once, but the settings live in the database
     * and change while it runs; `stored()` reads a cache that saving clears, and `apply()` first restores the config
     * values of settings applied earlier, so a removed setting falls back to `.env` here too. Jobs on the `sync`
     * connection run inside the current request, which applied the settings at boot; re-applying there would write
     * the site default back into `app.locale` after `SetLocale` set the user's language. The storage link check is
     * forgotten too, so a worker does not keep answering "linked" after the link went away.
     */
    protected function configureQueue(): void
    {
        Queue::before(function (JobProcessing $event): void {
            if ($event->connectionName === 'sync') {
                return;
            }

            $this->app->make(SiteSettings::class)->apply();
            $this->app->make(DirectServing::class)->refresh();
            Mail::forgetMailers();
        });
    }

    /**
     * Pick the image driver and let Livewire accept the largest media file.
     */
    protected function configureMedia(): void
    {
        config([
            'images.default' => extension_loaded('imagick') ? 'imagick' : 'gd',
            'livewire.temporary_file_upload.rules' => [
                'required',
                'file',
                'max:'.(max((int) config('media.limits.image_mb'), (int) config('media.limits.document_mb')) * 1024),
            ],
        ]);
    }

    /**
     * Let super admins pass every authorization check.
     *
     * Returning null for everyone else lets the regular permission checks decide.
     */
    protected function configureAuthorization(): void
    {
        Gate::before(
            fn (User $user): ?bool => $user->isSuperAdmin() ? true : null,
        );
    }

    /**
     * Translate literal strings that vendor packages pass to __().
     */
    protected function configureTranslations(): void
    {
        Lang::handleMissingKeysUsing(
            fn (string $key, array $replace, ?string $locale): ?string => VendorTranslations::translate($key, $replace, $locale),
        );
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }
}

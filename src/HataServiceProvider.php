<?php

namespace Hata;

use App\Models\User;
use Closure;
use Hata\Console\Commands\CheckContentAddresses;
use Hata\Console\Commands\CreateAdmin;
use Hata\Console\Commands\MakeContentType;
use Hata\Console\Commands\MoveMediaDisk;
use Hata\Console\Commands\OptimizeMedia;
use Hata\Console\Commands\PruneContentTrash;
use Hata\Console\Commands\PruneMediaDeletions;
use Hata\Console\Commands\SyncPermissions;
use Hata\Http\Middleware\EnsureRegistrationIsEnabled;
use Hata\Http\Middleware\LookUpContent;
use Hata\Http\Middleware\SetContentLocale;
use Hata\Http\Middleware\SetLocale;
use Hata\Models\Role;
use Hata\Policies\UserPolicy;
use Hata\Providers\ContentServiceProvider;
use Hata\Providers\FortifyServiceProvider;
use Hata\Support\Admin\AdminAssets;
use Hata\Support\Assets\HataAssets;
use Hata\Support\Localization\ContentLocales;
use Hata\Support\Localization\LanguageCatalog;
use Hata\Support\Media\DirectServing;
use Hata\Support\Permissions\PermissionRegistry;
use Hata\Support\Settings\SiteSettings;
use Hata\Support\Translation\VendorTranslations;
use Illuminate\Console\Events\CommandFinished;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Foundation\CachesConfiguration;
use Illuminate\Contracts\Foundation\CachesRoutes;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Foundation\Http\Kernel;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;
use Illuminate\View\Compilers\BladeCompiler;
use Laravel\Fortify\Features;
use Livewire\Livewire;

class HataServiceProvider extends ServiceProvider
{
    /**
     * The commands that write bootstrap/cache/config.php.
     *
     * @var list<string>
     */
    private const array CONFIG_CACHE_COMMANDS = ['config:cache', 'optimize'];

    /**
     * The configuration files of the package, merged under their own keys (interim: one `hata` key later).
     *
     * @var list<string>
     */
    private const array CONFIG_FILES = ['admin', 'content', 'languages', 'media', 'permissions'];

    public function register(): void
    {
        foreach (self::CONFIG_FILES as $file) {
            $this->mergeConfig($file);
        }

        $this->configurePackages();

        $this->app->singleton(
            PermissionRegistry::class,
            fn (): PermissionRegistry => (new PermissionRegistry)->registerMany(config('permissions.groups', [])),
        );

        $this->app->singleton(SiteSettings::class);
        $this->app->singleton(DirectServing::class);
        $this->app->singleton(ContentLocales::class);
        $this->app->singleton(AdminAssets::class);

        $this->registerLocalizedRoutes();
        $this->registerFluxOverrides();

        $this->app->register(ContentServiceProvider::class);
        $this->app->register(FortifyServiceProvider::class);
    }

    public function boot(): void
    {
        $this->keepSettingsOutOfTheConfigCache();
        $this->app->make(SiteSettings::class)->apply();
        $this->configureQueue();
        $this->configureMedia();
        $this->configureTranslations();
        $this->configureAuthorization();
        $this->configureViews();
        $this->configureAssets();
        $this->configureMiddleware();
        $this->configureRoutes();

        $this->loadMigrationsFrom(Hata::path('database/migrations'));

        $this->commands([
            CheckContentAddresses::class,
            CreateAdmin::class,
            MakeContentType::class,
            MoveMediaDisk::class,
            OptimizeMedia::class,
            PruneContentTrash::class,
            PruneMediaDeletions::class,
            SyncPermissions::class,
        ]);

        $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
            $schedule->command('media:prune-deleted')->daily();
            $schedule->command('content:prune-trash')->daily();
        });

        // A Livewire component on a public page keeps the page's content language on its update requests.
        Livewire::addPersistentMiddleware(SetContentLocale::class);
    }

    /**
     * Like `mergeConfigFrom()`, except that a cached config without the file's key still gets it: a cache built
     * before Hata was installed, or one Testbench's fresh application (which never reads the cache) believes in.
     */
    private function mergeConfig(string $file): void
    {
        $config = $this->app->make('config');

        if ($this->app instanceof CachesConfiguration && $this->app->configurationIsCached() && $config->has($file)) {
            return;
        }

        $config->set($file, array_merge(require Hata::path("config/{$file}.php"), (array) $config->get($file, [])));
    }

    /**
     * The settings of Laravel's own packages that Hata depends on, set at runtime so the host's config files need no
     * edits: the interface languages, Fortify's features, views and home, and spatie's role model.
     */
    private function configurePackages(): void
    {
        config([
            'app.available_locales' => config('app.available_locales') ?? [
                'en' => 'English',
                'uk' => 'Українська',
            ],
            'permission.models.role' => Role::class,
            'fortify.home' => '/dashboard',
            'fortify.views' => true,
            'fortify.lowercase_usernames' => true,
            // EnsureRegistrationIsEnabled answers 404 on the registration routes while registration is turned off
            // in the site settings (admin.registration_enabled).
            'fortify.middleware' => ['web', EnsureRegistrationIsEnabled::class],
            'fortify.limiters' => [
                'login' => 'login',
                'two-factor' => 'two-factor',
                'passkeys' => 'passkeys',
            ],
            'fortify.features' => [
                // Always registered; EnsureRegistrationIsEnabled hides it while it's turned off.
                Features::registration(),
                Features::resetPasswords(),
                Features::twoFactorAuthentication([
                    'confirm' => true,
                    'confirmPassword' => true,
                ]),
                Features::passkeys([
                    'confirmPassword' => true,
                ]),
            ],
            // Livewire's own provider registers these namespaces (and their view and Blade paths) from its config
            // at boot, whichever provider boots first.
            'livewire.component_namespaces.pages' => Hata::path('resources/views/pages'),
            'livewire.component_namespaces.layouts' => Hata::path('resources/views/layouts'),
        ]);
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
     * Hata's versions of a few Flux components (`resources/views/flux`). Blade looks a `flux:` component up in the
     * paths of that prefix in the order they were added, and Flux adds its own at boot, so these are added as soon
     * as the compiler exists, before any provider boots.
     */
    private function registerFluxOverrides(): void
    {
        $this->callAfterResolving(BladeCompiler::class, function (BladeCompiler $blade): void {
            $blade->anonymousComponentPath(Hata::path('resources/views/flux'), 'flux');
        });
    }

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
     * Re-apply the site settings before every queued job. A worker boots once, but the settings live in the database
     * and change while it runs; `stored()` reads a cache that saving clears, and `apply()` first restores the config
     * values of settings applied earlier, so a removed setting falls back to `.env` here too. Jobs on the `sync`
     * connection run inside the current request, which applied the settings at boot; re-applying there would write
     * the site default back into `app.locale` after `SetLocale` set the user's language. The storage link check is
     * forgotten too, so a worker does not keep answering "linked" after the link went away.
     */
    private function configureQueue(): void
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
    private function configureMedia(): void
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
     * Let super admins pass every authorization check. Returning null for everyone else lets the regular permission
     * checks decide. The user model lives in the host, where Laravel would not guess its policy.
     */
    private function configureAuthorization(): void
    {
        Gate::policy(User::class, UserPolicy::class);

        Gate::before(
            fn (User $user): ?bool => $user->isSuperAdmin() ? true : null,
        );
    }

    /**
     * The package's translations, and the literal strings that vendor packages pass to __().
     */
    private function configureTranslations(): void
    {
        $this->loadTranslationsFrom(Hata::path('resources/lang'));

        Lang::handleMissingKeysUsing(
            fn (string $key, array $replace, ?string $locale): ?string => VendorTranslations::translate($key, $replace, $locale),
        );
    }

    /**
     * The package's views come before the host's, so its anonymous Blade components (`components.*`) are found
     * without a prefix too, and so are its Livewire components.
     */
    private function configureViews(): void
    {
        View::prependLocation(Hata::path('resources/views'));
        Livewire::addLocation(viewPath: Hata::path('resources/views/components'));
    }

    /**
     * The built admin and site assets are static files: published to `public/vendor/hata`, which Laravel's skeleton
     * refreshes on every `composer update` through the `laravel-assets` tag. Tags come from Hata's own Vite instance.
     */
    private function configureAssets(): void
    {
        $this->publishes(
            [Hata::path('dist') => public_path(HataAssets::DIRECTORY)],
            ['laravel-assets', 'hata-assets'],
        );

        Blade::directive('hataAssets', fn (string $entries): string => '<?php echo \\'.HataAssets::class."::tags({$entries}); ?>");
        Blade::directive('hataFonts', fn (): string => '<?php echo \\'.HataAssets::class.'::fonts(); ?>');
    }

    /**
     * `SetLocale` runs on the whole `web` group, and `LookUpContent` before the session starts (routes/web.php).
     */
    private function configureMiddleware(): void
    {
        /** @var Kernel $kernel */
        $kernel = $this->app->make(HttpKernel::class);

        $kernel->appendMiddlewareToGroup('web', SetLocale::class);
        $kernel->addToMiddlewarePriorityBefore(StartSession::class, LookUpContent::class);
    }

    private function configureRoutes(): void
    {
        if ($this->app instanceof CachesRoutes && $this->app->routesAreCached()) {
            return;
        }

        Route::middleware('web')->group(Hata::path('routes/web.php'));
    }
}

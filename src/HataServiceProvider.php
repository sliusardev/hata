<?php

namespace Hata;

use Closure;
use Composer\InstalledVersions;
use Hata\Console\Commands\CheckContentAddresses;
use Hata\Console\Commands\CreateAdmin;
use Hata\Console\Commands\InstallCommand;
use Hata\Console\Commands\MakeContentType;
use Hata\Console\Commands\MoveMediaDisk;
use Hata\Console\Commands\OptimizeMedia;
use Hata\Console\Commands\PruneContentTrash;
use Hata\Console\Commands\PruneMediaDeletions;
use Hata\Console\Commands\SyncPermissions;
use Hata\Contracts\AdminUser;
use Hata\Http\Middleware\EnsureRegistrationIsEnabled;
use Hata\Http\Middleware\LookUpContent;
use Hata\Http\Middleware\SetContentLocale;
use Hata\Http\Middleware\SetLocale;
use Hata\Models\Entry;
use Hata\Models\EntryGroup;
use Hata\Models\Role;
use Hata\Models\Setting;
use Hata\Policies\UserPolicy;
use Hata\Providers\ContentServiceProvider;
use Hata\Providers\FortifyServiceProvider;
use Hata\Support\Admin\AdminAssets;
use Hata\Support\Admin\AdminMenu;
use Hata\Support\Admin\AdminMenuItem;
use Hata\Support\Admin\SettingsSection;
use Hata\Support\Admin\SettingsSections;
use Hata\Support\Assets\HataAssets;
use Hata\Support\Assets\HataVite;
use Hata\Support\Config\HataConfig;
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
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Foundation\Application;
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
use Illuminate\Support\ServiceProvider;
use Illuminate\Translation\FileLoader;
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
     * The layouts (`resources/views/layouts`) that are also Blade components, `<x-hata::layouts.…>`.
     *
     * @var list<string>
     */
    public const array LAYOUTS = ['app', 'app.header', 'app.sidebar', 'auth', 'auth.card', 'auth.simple', 'auth.split', 'focus', 'site'];

    public function register(): void
    {
        HataConfig::apply($this->app, $this->app->make('config'));

        $this->mergeSpatiePermissionDefaults();
        $this->configurePackages();

        $this->registerFrameworkTranslations();

        $this->app->singleton(
            PermissionRegistry::class,
            fn (): PermissionRegistry => (new PermissionRegistry)->registerMany(config('hata.permissions.groups', []), 'hata::permissions'),
        );

        $this->app->singleton(SiteSettings::class);
        $this->defineSettings();
        $this->app->singleton(DirectServing::class);
        $this->app->singleton(ContentLocales::class);
        $this->app->singleton(AdminAssets::class);
        $this->app->singleton(AdminMenu::class);
        $this->app->singleton(SettingsSections::class);
        $this->app->scoped(HataVite::class, fn ($app): HataVite => HataAssets::makeVite(
            $app->bound('hata.hot_file') ? $app->make('hata.hot_file') : Hata::path('hot'),
        ));

        $this->registerLocalizedRoutes();
        $this->registerFluxOverrides();
        $this->registerMorphMap();

        $this->app->register(ContentServiceProvider::class);
        $this->app->register(FortifyServiceProvider::class);
    }

    /**
     * Declare the site setting keys Hata itself stores.
     */
    private function defineSettings(): void
    {
        $settings = $this->app->make(SiteSettings::class);

        // General
        $settings->define('site_name', 'app.name');
        $settings->define('default_locale', 'app.locale');
        $settings->define('timezone', 'hata.admin.timezone');
        $settings->define('registration_enabled', 'hata.admin.registration_enabled', 'bool');
        $settings->define('default_role', 'hata.admin.default_role');

        // Mail
        $settings->define('mail_mailer', 'mail.default');
        $settings->define('mail_host', 'mail.mailers.smtp.host');
        $settings->define('mail_port', 'mail.mailers.smtp.port', 'int');
        $settings->define('mail_scheme', 'mail.mailers.smtp.scheme');
        $settings->define('mail_username', 'mail.mailers.smtp.username');
        $settings->define('mail_password', 'mail.mailers.smtp.password', encrypted: true);
        $settings->define('mail_from_address', 'mail.from.address');
        $settings->define('mail_from_name', 'mail.from.name');

        // Logo
        $settings->define('logo_light', 'hata.admin.logo.light', 'int');
        $settings->define('logo_dark', 'hata.admin.logo.dark', 'int');

        // Colors
        $settings->define('color_scheme', 'hata.admin.colors.scheme');
        $settings->define('color_accent', 'hata.admin.colors.accent');
        $settings->define('color_gray', 'hata.admin.colors.gray');
        $settings->define('color_tint_sidebar', 'hata.admin.colors.tint_sidebar', 'bool');

        // Media: optimization and limits
        $settings->define('media_optimize', 'hata.media.optimize.enabled', 'bool');
        $settings->define('media_max_side', 'hata.media.optimize.max_side', 'int');
        $settings->define('media_quality', 'hata.media.optimize.quality', 'int');
        $settings->define('media_format', 'hata.media.optimize.format');
        $settings->define('media_strip_exif', 'hata.media.optimize.strip_exif', 'bool');
        $settings->define('media_client_resize', 'hata.media.optimize.client_resize', 'bool');
        $settings->define('media_keep_originals', 'hata.media.optimize.keep_originals', 'bool');
        $settings->define('media_max_image_mb', 'hata.media.limits.image_mb', 'int');
        $settings->define('media_max_document_mb', 'hata.media.limits.document_mb', 'int');

        // Media: storage
        $settings->define('media_storage', 'hata.media.disk');
        $settings->define('media_s3_endpoint', 'filesystems.disks.s3.endpoint');
        $settings->define('media_s3_region', 'filesystems.disks.s3.region');
        $settings->define('media_s3_bucket', 'filesystems.disks.s3.bucket');
        $settings->define('media_s3_key', 'filesystems.disks.s3.key');
        $settings->define('media_s3_secret', 'filesystems.disks.s3.secret', encrypted: true);
        $settings->define('media_s3_path_style', 'filesystems.disks.s3.use_path_style_endpoint', 'bool');
        $settings->define('media_s3_public_url', 'filesystems.disks.s3.url');
        $settings->define('media_s3_prefix', 'filesystems.disks.s3.root');

        // Media: probe results the app keeps for itself (no config path)
        $settings->define('media_direct_state');
        $settings->define('media_direct_url');
        $settings->define('media_direct_checked_at');
        $settings->define('media_direct_attempted_at');
        $settings->define('media_direct_attempted_url');

        // Content
        $settings->define('content_locales', 'hata.content.locales');
        $settings->define('content_default_locale', 'hata.content.default_locale');
        $settings->define('content_disabled_types', 'hata.content.disabled_types');
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
        $this->registerAdminMenu();
        $this->registerSettingsSections();

        $this->loadMigrationsFrom(Hata::path('database/migrations'));

        $this->commands([
            CheckContentAddresses::class,
            CreateAdmin::class,
            InstallCommand::class,
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
     * Hata's own sidebar sections, in the order and with the permissions the sidebar always had. The admin-only
     * items need `admin.access` as well, as the whole admin part of the sidebar did; the content types come from
     * `ContentServiceProvider`.
     */
    private function registerAdminMenu(): void
    {
        $menu = $this->app->make(AdminMenu::class);
        $admin = fn (?string $permission = null): Closure => fn (AdminUser $user): bool => Gate::forUser($user)->allows('admin.access')
            && ($permission === null || Gate::forUser($user)->allows($permission));

        $menu->group('platform', 'hata::navigation.platform', 10)
            ->group('content', 'hata::navigation.content', 100)
            ->group('administration', 'hata::navigation.administration', 200)
            ->group('account', 'hata::navigation.account', 300)
            ->add(new AdminMenuItem('dashboard', 'hata::navigation.dashboard', 'home', 'admin.dashboard', $admin(), 'platform'))
            ->add(new AdminMenuItem('media', 'hata::navigation.media', 'photo', 'admin.media.index', $admin('media.view'), 'content', 900, 'admin.media.*'))
            ->add(new AdminMenuItem('users', 'hata::navigation.users', 'users', 'admin.users.index', $admin('users.view'), 'administration', 10, 'admin.users.*'))
            ->add(new AdminMenuItem('roles', 'hata::navigation.roles', 'shield-check', 'admin.roles.index', $admin('roles.view'), 'administration', 20, 'admin.roles.*'))
            ->add(new AdminMenuItem('settings', 'hata::navigation.site_settings', 'adjustments-horizontal', 'admin.settings.edit', $admin('settings.edit'), 'administration', 30, 'admin.settings.*'))
            ->add(new AdminMenuItem('maintenance', 'hata::navigation.maintenance', 'wrench-screwdriver', 'admin.maintenance', $admin('maintenance.run'), 'administration', 40))
            ->add(new AdminMenuItem('profile', 'hata::navigation.my_profile', 'user-circle', 'profile.edit', null, 'account', 10, 'profile.edit|language.edit|security.edit|appearance.edit'));
    }

    /**
     * Hata's own sections of the site settings page; the others (languages, content, appearance, media) register
     * with their own task.
     */
    private function registerSettingsSections(): void
    {
        $this->app->make(SettingsSections::class)
            ->add(new SettingsSection('site', 'hata::site_settings.site.heading', 'cog-6-tooth', 'hata::admin.settings.sections.site', 'settings.edit', 10))
            ->add(new SettingsSection('languages', 'hata::site_settings.languages.heading', 'language', 'hata::admin.settings.sections.languages', 'settings.edit', 20))
            ->add(new SettingsSection('content', 'hata::site_settings.content.heading', 'squares-2x2', 'hata::admin.settings.sections.content', 'settings.edit', 30))
            ->add(new SettingsSection('access', 'hata::site_settings.access.heading', 'lock-closed', 'hata::admin.settings.sections.access', 'settings.edit', 40))
            ->add(new SettingsSection('mail', 'hata::site_settings.mail.heading', 'envelope', 'hata::admin.settings.sections.mail', 'settings.edit', 50));
    }

    /**
     * Hata's models stored in a `*_type` column (`media_usages.usable_type`) get short names, so the database never
     * holds a `Hata\…` class name. Not enforced: the host's own polymorphic models keep their class names.
     */
    private function registerMorphMap(): void
    {
        Relation::morphMap([
            'entry' => Entry::class,
            'entry_group' => EntryGroup::class,
            'setting' => Setting::class,
        ]);
    }

    /**
     * Gives spatie/laravel-permission its whole default config when the site has not published one (or only part of
     * it). Spatie's own provider merges its file one level deep, so a host `permission` array that holds just
     * `models.role` (which Hata sets below) would hide spatie's other `models` and every other key, and the
     * PermissionRegistrar would fail to build. Merging here, in register(), is independent of provider order: if
     * spatie registered first, its defaults are already there and this changes nothing. Keys the host sets win.
     */
    private function mergeSpatiePermissionDefaults(): void
    {
        $config = $this->app->make('config');

        if ($this->app instanceof CachesConfiguration && $this->app->configurationIsCached() && $config->has('permission.models.permission')) {
            return;
        }

        $path = InstalledVersions::getInstallPath('spatie/laravel-permission');

        if ($path === null || ! is_file($file = $path.'/config/permission.php')) {
            return;
        }

        $config->set('permission', array_replace_recursive(require $file, (array) $config->get('permission', [])));
    }

    /**
     * The settings of Laravel's own packages that Hata depends on, set at runtime so the host's config files need no
     * edits: Fortify's features, views and home, and spatie's role model.
     */
    private function configurePackages(): void
    {
        config([
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
                'max:'.(max((int) config('hata.media.limits.image_mb'), (int) config('hata.media.limits.document_mb')) * 1024),
            ],
        ]);
    }

    /**
     * Let super admins pass every authorization check. Returning null for everyone else lets the regular permission
     * checks decide. The user model lives in the host, where Laravel would not guess Hata's policy for it, and where
     * a policy Laravel does guess (`App\Policies\UserPolicy`) would otherwise win over a match by interface: so the
     * configured class gets an exact mapping, and the interface stays as the fallback for a class configured later.
     * Neither throws, so a host that has not added the trait yet (before `hata:install`) still boots.
     */
    private function configureAuthorization(): void
    {
        $model = config('auth.providers.users.model');

        if (is_string($model) && is_subclass_of($model, AdminUser::class)) {
            Gate::policy($model, UserPolicy::class);
        }

        Gate::policy(AdminUser::class, UserPolicy::class);

        Gate::before(
            fn (mixed $user): ?bool => $user instanceof AdminUser && $user->isSuperAdmin() ? true : null,
        );
    }

    /**
     * Laravel reads its own groups by their global names (`validation.required`, `auth.failed`, the password
     * broker and pagination lines), so Hata's lines for them (`resources/lang-framework`) cannot sit under the
     * `hata` namespace. They are inserted into the loader's paths between the framework's English and the site's
     * `lang/`, so a site's own file (lang:publish, laravel-lang) always wins over Hata, and Hata over Laravel.
     */
    private function registerFrameworkTranslations(): void
    {
        $this->app->extend('translation.loader', function ($loader, Application $app) {
            if (! $loader instanceof FileLoader) {
                return $loader;
            }

            $paths = $loader->paths();
            $sitePath = array_search($app->langPath(), $paths, true);
            array_splice($paths, is_int($sitePath) ? $sitePath : count($paths), 0, [Hata::path('resources/lang-framework')]);

            $extended = new FileLoader($app->make('files'), $paths);

            foreach ($loader->jsonPaths() as $jsonPath) {
                $extended->addJsonPath($jsonPath);
            }

            foreach ($loader->namespaces() as $namespace => $hint) {
                $extended->addNamespace($namespace, $hint);
            }

            return $extended;
        });
    }

    /**
     * The package's translations, and the literal strings that vendor packages pass to __().
     */
    private function configureTranslations(): void
    {
        $this->loadTranslationsFrom(Hata::path('resources/lang'), 'hata');

        Lang::handleMissingKeysUsing(
            fn (string $key, array $replace, ?string $locale): ?string => VendorTranslations::translate($key, $replace, $locale),
        );
    }

    /**
     * Everything is under `hata`, so nothing is added to the host's own view paths, anonymous component paths or
     * Livewire locations, and no unprefixed component name (welcome, layouts.app) can ever resolve to Hata:
     *
     * - views `hata::…` (`resources/views`; a site overrides one in `resources/views/vendor/hata`);
     * - anonymous Blade components `<x-hata::…>`, which Blade finds as the views `hata::components.…`;
     * - the layouts as components, `<x-hata::layouts.app>`: each is an alias of the view of the same name (LAYOUTS),
     *   so a site's override of the view is the component too;
     * - Livewire SFC pages and components `hata::…` (`resources/views/livewire`). The namespace is set on Livewire's
     *   finder directly: Livewire only (re)writes the namespaces listed in its `component_namespaces` config, which
     *   holds no `hata`, so the provider order does not matter. Full pages name their layout (`hata::layouts.app`).
     */
    private function configureViews(): void
    {
        $this->loadViewsFrom(Hata::path('resources/views'), 'hata');

        $this->callAfterResolving(BladeCompiler::class, function (BladeCompiler $blade): void {
            foreach (self::LAYOUTS as $layout) {
                $blade->component("hata::layouts.{$layout}", "hata::layouts.{$layout}");
            }
        });

        Livewire::addNamespace('hata', viewPath: Hata::path('resources/views/livewire'));
    }

    /**
     * The built admin and site assets are static files: published to `public/vendor/hata`, which Laravel's skeleton
     * refreshes on every `composer update` through the `laravel-assets` tag. Tags come from Hata's own Vite instance.
     */
    private function configureAssets(): void
    {
        $this->publishes([Hata::path('config/hata.php') => config_path('hata.php')], 'hata-config');

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

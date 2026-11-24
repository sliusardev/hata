# Package

Hata is the Composer package `sliusardev/hata` (decision 72 in CLAUDE.md; design
`docs/superpowers/specs/2026-11-23-hata-package-design.md`). A client site is a fresh Laravel 13 app that requires it
and runs `php artisan hata:install`. This spec is the contract between Hata and a site or a module: how it is
installed and updated, what it changes in the site, and the names a site or module may rely on. The README holds the
same as a guide with examples.

## Distribution

- Public GitHub repository `sliusardev/hata`, `license: MIT` (decision 73). Listed on Packagist
  (`https://packagist.org/packages/sliusardev/hata`, updated by the GitHub hook on every push and tag); a site
  requires `sliusardev/hata:^0.1` with no `repositories` entry, token or SSH key.
- Versions: semantic versioning from `v0.1.0` (2026-11-24). While Hata is `0.x`, a breaking change raises the minor
  version, so a site's `^0.1` takes only the fixes of `0.1.*`.
- Requirements: PHP `^8.4` with `intl`, Laravel `^13.17`, GD or Imagick. `livewire/flux` is pinned to `~2.21.0`
  because Flux's CSS is compiled into Hata's assets; a site gets a newer Flux minor only with a Hata release built on
  it. No Flux Pro.
- Built assets are committed in `dist/` (`npm run build`, `base: /vendor/hata/`), so a site never runs npm for Hata.

## Install: `php artisan hata:install`

`Hata\Console\Commands\InstallCommand`, options `--name`, `--email`, `--password`, `--no-migrate`. Every step is
idempotent; the command may run again at any time. It refuses to run with a cached config (`config:clear` first),
because a published `config/hata.php` and the `ADMIN_*` values would not be read.

Steps, in order:

1. Host-file edits (`Hata\Support\Install\HostFiles`, paths from `HostPaths`). Each edits the file by the shape
   Laravel's skeleton and Pint give it, never writes a file that no longer parses, and otherwise leaves it untouched
   and prints the lines to add by hand:
   - `app/Models/User.php` (found through Composer's class loader): `implements AdminUser` and `use IsAdminUser`
     with their imports.
   - `routes/web.php`: the skeleton's `/` → `welcome` route removed (Hata's `home` takes `/`), then
     `resources/views/welcome.blade.php` deleted when this run removed the route.
   - `public/robots.txt`: deleted only when it is exactly Laravel's stock file (`User-agent: *` / `Disallow:`), since
     the web server would serve it before Hata's `/robots.txt` route, which adds the `Sitemap:` line; a changed
     file is kept and the line to add is printed. Skipped when `hata.seo.robots` is off. A site installed before
     v0.3.0 deletes a stock `public/robots.txt` by hand.
   - `app/Providers/AppServiceProvider.php`: `configureDefaults()` (`Date::use(CarbonImmutable::class)`,
     `DB::prohibitDestructiveCommands()` in production, `Password::defaults()`) from
     `stubs/app-service-provider-defaults.stub`, called first in `boot()`. These are the site's defaults, not Hata's.
   - `database/seeders/DatabaseSeeder.php`: `$this->call(HataSeeder::class)`; the skeleton's Test User and
     `WithoutModelEvents` are removed (Faker is dev-only; `WithoutModelEvents` would leave the admin without a
     search index). A changed seeder that uses `WithoutModelEvents` is left for a by-hand fix.
   - `composer.json` → `scripts.post-autoload-dump`: `@php artisan vendor:publish --tag=hata-assets --force --ansi`
     right after `@php artisan package:discover --ansi` (at the end when that line is missing), and
     `scripts.post-update-cmd`: both `@php artisan vendor:publish --tag=laravel-assets --ansi --force` and
     `@php artisan view:clear --ansi`. A script written as a single string is read as a one-line list; a missing key is
     added; a file whose scripts are not lists of strings is left for a by-hand fix.
2. `config/hata.php` copied from the package unless the site has one (the same file as
   `vendor:publish --tag=hata-config`). Spatie's migration is never published (Hata ships those tables).
3. `vendor:publish --tag=laravel-assets --force` (Hata's `dist/` → `public/vendor/hata`).
4. `migrate --force` (unless `--no-migrate`).
5. Seeding: the super admin from `ADMIN_NAME`/`ADMIN_EMAIL`/`ADMIN_PASSWORD` (`hata.admin.initial_admin`), else the
   options, else prompts on an interactive console, else a by-hand `php artisan admin:create`; never a made-up
   password. Then `HataSeeder` (permissions sync, system roles, the super admin, the content languages: decision 56
   and `docs/specs/languages.md`). When this process still holds the user model from before step 1, the seeder runs
   in a new process with the admin passed in the environment.
6. `storage:link` when a link of `filesystems.links` is missing.
7. The report: one row per step, `done`, `already done`, `do by hand`, `skipped` or `failed`; the by-hand lines; the
   web-server caching reminder (Apache: copy `vendor/sliusardev/hata/stubs/htaccess` to `public/.htaccess`; nginx:
   the README's location blocks); the sign-in URL.

Exit code: 1 when any step `failed` (assets, migrations, seeding, an unexpected exception); 0 otherwise, also with
by-hand lines. The report is always printed.

## Update

- `composer update` (or `composer update sliusardev/hata`) brings code, views, translations, migrations and `dist/`.
  The site's `post-update-cmd` republishes `public/vendor/hata` and clears compiled views (Blade recompiles a template
  only when its own file changes, not when a component it uses moved). Both lines are ensured by `hata:install`.
- Then `php artisan migrate` (Admin → Maintenance can run it) and `php artisan db:seed --force` or
  `php artisan permissions:sync` when the update adds permissions (both are safe to rerun).
- After changing `.env` or `config/hata.php` on a site that caches: `php artisan optimize`.

`seo` holds `description` (`[code => text]`, the site description per content language), `image` (a media id, the share
image), `sitemap` (`HATA_SITEMAP`, default true) and `robots` (`HATA_ROBOTS`, default true): the last two switch off
Hata's `/sitemap.xml` and `/robots.txt` routes for a site that serves its own files (read while the routes register,
so set them before the routes load). The first two are stored by Site settings. `content.home_page` is the chosen home
page's record id, also stored by Site settings.

### What changed for v0.3.0 (head tags, sitemap, home page)

- **Breaking:** `hata::welcome` (`resources/views/welcome.blade.php`) is removed; `/` is `HomeController`, which shows the
  page chosen in Site settings or the neutral `hata::site.home`. A site that overrode
  `resources/views/vendor/hata/welcome.blade.php` loses that file's effect (override `hata::site.home` instead).
- Public pages print their head through `@hataHead` (`Hata\Support\Seo\PageMeta`): description, canonical, Open
  Graph and Twitter tags. A site that overrode `layouts/site` keeps its own head until it replaces it with `@hataHead`.
- `/sitemap.xml` and `/robots.txt` are served by Hata. The web server answers a file in `public/` first, so a site
  installed before v0.3.0 deletes a stock `public/robots.txt` (`User-agent: *` / `Disallow:`) by hand;
  `hata:install` does it for new installs and never touches a changed file.
- New settings in Site settings: home page, site description per language, image for sharing.

### What changed for v0.2.0 (the settings sections)

Breaking for code that reached into the old settings page; a site that only installs Hata needs nothing:

- `SiteSettings::CASTS`, `::ENCRYPTED` and `::CONFIG_PATHS` are removed. The keys are a registry: `SiteSettings::define()` (see below), read back with `definition()` and `isDefined()`.
- `Hata\Livewire\Forms\SiteSettingsForm` is removed, and `form.*` no longer exists on the page. Each section is its own component over `values.*` (`SettingsSectionPage`).
- The tabs are merged into seven sections: General is **Site**, Logo and Colors are **Appearance**. Old links keep working through aliases (`?tab=general` opens `site`, `?tab=logo` and `?tab=colors` open `appearance`).
- Save stores only the fields the admin changed, so an untouched value that comes from `.env` is no longer frozen into the database. A typed secret always counts as changed.
- Appearance saves the logos together with the colors, all or nothing; choosing a logo no longer stores it at once.
- Access is per section, and the menu item and the page need at least one section the user may open (they no longer need `settings.edit` itself).

## Deploying

- `HataVite` reads the manifest from the package's own `dist/`, so the tags always name the installed version's
  hashed files; they must also be in `public/vendor/hata`. A deploy that installs from the lock file
  (`git pull && composer install --no-dev`) never runs `post-update-cmd`, so the publish lives in
  `post-autoload-dump`, which Composer runs on both `install` and `update` (and `dump-autoload`): a cheap copy of
  `dist/` each time.
- What runs automatically: the assets publish (every install, update and dump-autoload) and, on `composer update`,
  every package's `laravel-assets` and `view:clear`. The deploy script also runs `php artisan migrate --force` and
  `php artisan optimize` (and `db:seed --force` when an update adds permissions, `queue:restart` with a worker).
- Web server: `stubs/htaccess` caches `/storage/media/` images for a week and `/vendor/hata/assets/` (hashed names) for
  a year with `immutable`; the README has the same two nginx `location` blocks.

## Admin path

`hata.admin.path` (env `HATA_ADMIN_PATH`, default `admin`). `Hata\Support\Admin\AdminPath::resolve()` validates it
while the routes register: one segment of `[a-z0-9]` and inner hyphens, not a catalogue language code, not a segment
Hata, Fortify, Livewire or Laravel use (`login`, `settings`, `livewire`, …), not the first segment of another route.
A bad value throws an `InvalidArgumentException` naming the reason. Route names stay `admin.*`; code links with
`route('admin.…')`, never `/admin`. With cached routes, `php artisan optimize` after a change.

## Config

One published file, `config/hata.php` (tag `hata-config`), sections `admin`, `content`, `media`, `seo`.
`Hata\Support\Config\HataConfig::merge()` puts the site's file over the package's defaults: nested maps merge key by
key (a site's file from an older Hata still gets newer keys), lists (`content.types`, `content.reserved_slugs`)
replace the default list whole, and `admin.interface_locales` replaces whole (a site can drop `uk`). Data that is not
configuration stays internal under `config/internal/` (`hata.languages`, the catalogue; `hata.permissions`, groups,
role defaults and reserved permissions) and is never published. Site settings still write over these keys at
runtime (`docs/specs/site-settings.md`). Laravel's own configs (`auth`, `filesystems`, `queue`, `cache`, `mail`) stay
the site's; Hata supplies spatie's `permission` defaults and sets Fortify's options at runtime, so the site needs
neither `config/permission.php` nor `config/fortify.php`.

## Languages

- Interface languages (admin, account, sign-in): `hata.admin.interface_locales`; Hata ships `en` and `uk`. A site adds
  one in its config plus `lang/vendor/hata/<code>/*.php` (every file of `resources/lang/en`), and overrides a single
  line in `lang/vendor/hata/<locale>/<group>.php`.
- Content languages (the public site) are chosen in Site settings → Languages; `HataSeeder` stores the interface
  default as the first one at install (`docs/specs/languages.md`).
- Laravel's global groups (`validation`, `passwords`, `pagination`, `auth.failed|password|throttle`) are read in the
  order Laravel → Hata (`resources/lang-framework`) → the site's `lang/`, so a site's line wins.

## What a site accepts by installing Hata

These are Hata features, not defaults a site can opt out of:

- Routes: `/` (`home`), the content fallback route (`->fallback()`, so any route of the site wins), Fortify's
  sign-in routes, `/settings/*`, the admin group, `/dashboard`, the media routes (`/media/…`, `/storage/media/…`).
- `SetLocale` appended to the `web` group (and a Livewire persistent middleware); `LookUpContent` before
  `StartSession` in the middleware priority.
- Site settings written over `app.name`, `app.locale`, `mail.*`, `filesystems.disks.s3.*` and `hata.*` at boot and
  before every queued job.
- `Lang::handleMissingKeysUsing()` for vendor literals (decision 7). Laravel keeps one handler: a site that needs its
  own calls `Hata\Support\Translation\VendorTranslations::translate($key, $replace, $locale)` from it first.
- Hata's `validation.attributes` (global, in `resources/lang-framework`) also name fields in the site's forms.
- `Relation::morphMap()` entries `entry`, `entry_group`, `setting` (never `enforceMorphMap()`).
- `Gate::before`: a `super-admin` passes every ability, the site's too.
- `permission.models.role` is `Hata\Models\Role`; spatie's tables (with Hata's `label`/`is_system` columns on
  `roles`).
- Fortify is configured by Hata, in `HataServiceProvider::register()` (`configurePackages()`), over whatever the site's
  `config/fortify.php` says: the whole `fortify.features` list (registration, which `EnsureRegistrationIsEnabled`
  hides while the site setting is off, password reset, two-factor with confirmation, passkeys), `fortify.views`
  (`true`), `fortify.home` (`/dashboard`), `fortify.middleware` (`web` plus `EnsureRegistrationIsEnabled`),
  `fortify.limiters` (`login`, `two-factor`, `passkeys`, with Hata's rate limiters of those names) and
  `fortify.lowercase_usernames` (`true`); Fortify's actions (`createUsersUsing`, `resetUserPasswordsUsing`) and views
  are Hata's (`Hata\Providers\FortifyServiceProvider`).
- `images.default` is `imagick` when the extension is loaded, else `gd`, and Livewire's
  `livewire.temporary_file_upload.rules` are `required|file|max:<the larger media limit>` (`configureMedia()`, at
  boot), so a site's own values for both are replaced.
- The configured user model (`auth.providers.users.model`) gets an exact `Gate::policy($model,
  Hata\Policies\UserPolicy::class)` (`configureAuthorization()`, at boot). It beats a policy Laravel would guess for
  it (`App\Policies\UserPolicy`) and the model's `#[UsePolicy]`. A site that needs its own user policy extends
  `Hata\Policies\UserPolicy` (Hata's admin calls its abilities) and registers it with `Gate::policy()` in its own
  `AppServiceProvider::boot()`, which runs after Hata's.
- The schedule entries `media:prune-deleted` and `content:prune-trash` (daily).

A site that must change one of these config values sets it in its own `AppServiceProvider` (`register()` for the
Fortify keys, `boot()` for the others): application providers register and boot after package providers. Hata
expects the values above, so a changed one is the site's to support.

Hata never sets `Date::use()`, `Password::defaults()` or `DB::prohibitDestructiveCommands()`: those are the site's,
written by the installer into its `AppServiceProvider`.

## Extension API (for a site's `app/` and for modules)

A module (such as a future `hata-shop` package) uses exactly the same API as code in the site; Hata's own sections
register through it.

- **User model:** the site's `App\Models\User` (or whatever `auth.providers.users.model` names) implements
  `Hata\Contracts\AdminUser` and uses `Hata\Models\Concerns\IsAdminUser`; Hata resolves it with `Hata::userModel()`.
  The trait only adds to the host's fillable, hidden and casts; an unguarded model (`$guarded = []`) stays unguarded
  (`docs/specs/users.md`).
- **Content types:** `ContentType` classes listed in `config/hata.php` → `content.types`.
  `php artisan make:content-type Recipes [--tree] [--key=] [--base=] [--icon=]` writes the class to
  `app/ContentTypes` (`App\ContentTypes`), adds it to the site's `config/hata.php` (publishing it first if missing)
  and adds lines to the site's `lang/<locale>/{content,permissions}.php` for every interface language. A type's labels
  come from `translationPrefix()`: `''` (the site's `lang/`) by default, `hata::` for the kit types, `shop::` for a
  module's. Details: `docs/specs/content.md`.
- **Permissions:** `app(PermissionRegistry::class)->register('shop', ['view', 'edit'], 'shop::permissions')` in a
  provider's `register()` or `boot()`; labels `shop::permissions.groups.shop` and `shop::permissions.items.shop.view`,
  falling back to the site's `permissions.*`. The prefix defaults to `permissions` (the site's own files). Then
  `php artisan permissions:sync`. Never through config (`docs/specs/roles.md`).
- **Settings sections:** see "Adding a settings section" below.
- **Public head (themes and modules):** write `@hataHead` once in the layout's `<head>` and fill
  `app(\Hata\Support\Seo\PageMeta::class)` from the controller (`setTitle()`, `setDescription()`, `setCanonical()`,
  `setImage()`, `setType('website'|'article')`, `setNoindex()`); anything left empty falls back to the site's. **Scoped
  services (`PageMeta`, `HomePage`) are resolved per call**, as a method argument or `app()`, never held in a
  controller's constructor: the router caches controller instances, which would keep the first request's state
  (`docs/specs/content.md`).
- **Admin menu:** `app(AdminMenu::class)->group(...)`/`->add(new AdminMenuItem(...))` in a provider's `boot()`
  (`docs/specs/admin-menu.md`).
- **Admin routes:** middleware `web, auth, verified, can:admin.access`, prefix `config('hata.admin.path')`, names
  `admin.`; each page authorizes its own permission. Full Livewire pages use `#[Layout('hata::layouts.app')]`, Blade
  pages `<x-hata::layouts.app :title="…">`.
- **Admin assets:** `app(AdminAssets::class)->add('vendor/shop', ['resources/css/admin.css'])` (build directory
  under `public`, Vite entries; the manifest is looked for at `.vite/manifest.json`, then `manifest.json`; optional
  hot file and manifest name). The admin `<head>` prints them after Hata's own tags.
- **Blade components and layouts:** `<x-hata::confirm-modal>`, `<x-hata::admin.row-actions>`,
  `<x-hata::admin.empty-state>`, `<x-hata::admin.rich-text>`, `<x-hata::flash-toast>`, `<x-hata::site-logo>`,
  `<x-hata::app-logo>`, `<x-hata::settings.layout>`, …; layouts `<x-hata::layouts.app>`, `…app.sidebar`,
  `…app.header`, `…auth` (`.simple`, `.card`, `.split`), `…focus`; views `hata::layouts.app`, `hata::layouts.focus`,
  `hata::layouts.site`.
- **Livewire:** pages `hata::admin.{users,roles,content,media,settings,maintenance}.*`, `hata::settings.*`;
  components `hata::media-picker`, `hata::translation-picker`.
- **Overriding views (themes):** a Blade view, component or layout is overridden at
  `resources/views/vendor/hata/<same path>` (e.g. `site/entry.blade.php` for the public record page). An overridden
  view stops receiving Hata's fixes, so the supported way is an extension point above; override public `site/*` and
  `layouts/site` views for a theme, admin views only with a reason. Livewire SFC pages (`resources/views/livewire/…`)
  cannot be overridden this way.
- **Translations:** a site's line in `lang/vendor/hata/<locale>/<group>.php` wins over Hata's.
- **Seeders:** the site's `DatabaseSeeder` calls `Hata\Database\Seeders\HataSeeder` (no `WithoutModelEvents` around
  it); the site's own seeders follow. The maintenance page lists the site's seeders (`docs/specs/maintenance.md`).
- **Migrations:** Hata's load from the package. Laravel's users, cache and jobs tables are the site's;
  `add_hata_columns_to_users_table` skips columns the site already has. Spatie's tables and the passkeys table are
  skipped when they exist. A site must not publish Fortify's two-factor migration or laravel/passkeys' migration after
  Hata's ran: Hata already ships those columns and that table, so the published one fails on a second create.
  From the first release on, a shipped migration is never edited.

## Adding a settings section

A site or a module adds a section to Site settings the way Hata's own seven are added (`docs/specs/site-settings.md`). Three parts: the setting keys, a Livewire component for the form, and the registration.

**1. Define the keys.** `app(SiteSettings::class)->define($key, ?$configPath, ?$cast, $encrypted)` in a provider's `register()` or `boot()`: the key, the config path its stored value is written over (null for a stored-only value the code reads itself with `SiteSettings::value()`, which decrypts and decodes it by its definition), a cast for the value that reaches config (`int`, `bool` or `array`) and `encrypted: true` for a secret (encrypted with `APP_KEY`). A key defined after the settings were applied is applied at once, so the order of providers does not matter. A key that is not defined is never stored (`put()` reports it).

**2. Write the component.** Extend `Hata\Livewire\Admin\Settings\SettingsSectionPage`:

- `key(): string` (static): the section's key, the same as its registration's.
- `fields(): array`: the setting keys the form edits (they are the keys of `$values`).
- `rules(): array`: validation rules keyed `values.<field>`. **Every field needs an exact `values.<field>` rule**; a field without one is reported (a `LogicException` in `local` and `testing`, a logged warning elsewhere) and is never saved, because only validated values are stored.
- Hooks, all optional: `load()` (the form's starting values; by default a config-backed key reads its config value, a stored-only key its stored value), `prepare($validated)` (cast the changed values; a blank becomes null, which removes the setting so the config value takes over), `beforePersist($changed)` (runs once after validation and before storing; return false to stop the save, as Media does to open its S3 confirmation, so validation never runs twice), `persist($changed)` (store them; by default `SiteSettings::put()`; override it to call an action, to store several things in one transaction or to turn a failure into a `ValidationException`), `secretFields()` (fields that start empty, are never loaded and are stored only when typed; every field defined with `encrypted: true` is treated as one automatically, so an encrypted setting never reaches the browser).
- The section's permission is checked on mount and on every later request by the `AuthorizesSettingsSection` trait hooks, which overriding `mount()` or `hydrate()` cannot remove. Check it yourself in every action you add with `$this->authorizeSection()`.
- The component's view has `<x-hata::admin.settings-section>` as its root, with the cards inside (`heading`, `description`, `:secrets="[...]"` for the secret fields and `:save="false"` for a section without a Save button). Never write `data-settings-section` by hand: the component carries it, and the page's leave-without-saving guard finds the open section through it.
- Bind fields with `wire:model="values.<field>"`.

**3. Register it** in a provider's `boot()` (never before the container is ready: nothing is checked until the page asks, so a component Livewire cannot resolve yet breaks nothing at boot):

```php
app(PermissionRegistry::class)->register('shop', ['settings']);   // labels in the site's lang/ (or 'shop::permissions')
Livewire::component('shop-settings', ShopSettings::class);
app(SiteSettings::class)
    ->define('shop_currency', 'shop.currency')
    ->define('shop_api_token', 'shop.token', encrypted: true);
app(SettingsSections::class)->add(
    new SettingsSection('shop', 'Shop', 'shopping-cart', 'shop-settings', 'shop.settings', 80),
);
```

`SettingsSection(key, label, icon, component, permission = 'settings.edit', order = 100)`: the key is lowercase words joined by hyphens; the label is a translation key or a plain string (passed through `__()`), the icon a Flux icon, the component a Livewire name or class, the permission a gate ability, a `Closure(AdminUser): bool` or null for any admin. Hata's sections use orders 10 to 70; pick another number to place yours. The module's translations live in its own files (`shop::…`) or the site's `lang/`. Run `php artisan permissions:sync` after registering a permission.

```php
class ShopSettings extends SettingsSectionPage
{
    public static function key(): string { return 'shop'; }

    protected function fields(): array { return ['shop_currency', 'shop_api_token']; }

    protected function secretFields(): array { return ['shop_api_token']; }

    protected function rules(): array
    {
        return [
            'values.shop_currency' => ['required', 'string', 'size:3'],
            'values.shop_api_token' => ['nullable', 'string', 'max:255'],
        ];
    }

    public function render(): string
    {
        return <<<'BLADE'
            <x-hata::admin.settings-section heading="Shop" :secrets="['shop_api_token']">
                <flux:card class="space-y-6">
                    <flux:input wire:model="values.shop_currency" label="Currency" />
                    <flux:input type="password" wire:model="values.shop_api_token" label="API token" viewable />
                </flux:card>
            </x-hata::admin.settings-section>
            BLADE;
    }
}
```

A complete worked example, with its tests, is `tests/fixtures/ShopSettingsSection.php` and `tests/Feature/Admin/Settings/SettingsSectionsTest.php`. The menu item "Site settings" is decided from the sections' permissions alone, without resolving any component, so a module section whose registration is broken (a component Livewire cannot resolve) is left out in production with a warning in the log while the item stays visible to a user who has only that section, and the page then answers 403. In `local` and `testing` the mistake throws, so the module's own tests catch it.

## Developing Hata

- The package is at `/Users/vitalii/Herd/hata`; the demo `/Users/vitalii/Herd/hata-demo` requires `sliusardev/hata:
  ^0.1` from Packagist like a client site (since 2026-11-24) and is served by Herd at https://hata.test. A change
  reaches it after a release: push `main`, an annotated tag `vX.Y.Z` pushed to GitHub (Packagist updates through the
  GitHub hook), then `composer update sliusardev/hata` in the demo. For unreleased work a `path` repository with
  `symlink: true` (`*@dev`) may be added there for a while. `npm run build` rewrites `dist/`; commit it with the
  source change.
- Shipped files: plain ASCII names only (no Livewire `⚡` prefix: macOS's `unzip` cannot extract UTF-8 names from the
  release zip and Composer's fallback needs `php-zip`; create components with `make:livewire --emoji=false`),
  guarded by `tests/Feature/Package/PackageFilesTest.php`; nothing runtime code reads may sit in an export-ignored
  path (`.gitattributes`).
- `php artisan migrate:fresh --seed` in the demo rebuilds it with the `ADMIN_*` super admin (the user runs database
  writes there).
- Tests: `composer test` (Pint, PHPStan level 7, Pest on Testbench). Suites `Unit`, `Feature`, `AdminPath`, `Install`.
  MySQL: `DB_CONNECTION=mysql DB_HOST=127.0.0.1 DB_DATABASE=hata_testing DB_USERNAME=root vendor/bin/pest`. Never run
  the SQLite and MySQL suites at the same time; `pest --parallel` is broken in this setup.
- CI (`.github/workflows/tests.yml`, push to `main` and pull requests): Pint and PHPStan; on PHP 8.5 a job that runs
  `npm ci && npm run build` and fails when `dist/` changes; the suite on PHP 8.4/8.5 × SQLite/MySQL 8 with
  `composer install` only.
- AI agents on client sites: `resources/boost/guidelines/core.blade.php`, which Laravel Boost adds to a site's
  guidelines when Boost is installed there.

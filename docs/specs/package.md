# Package

Hata is the Composer package `sliusardev/hata` (decision 72 in CLAUDE.md; design
`docs/superpowers/specs/2026-11-23-hata-package-design.md`). A client site is a fresh Laravel 13 app that requires it
and runs `php artisan hata:install`. This spec is the contract between Hata and a site or a module: how it is
installed and updated, what it changes in the site, and the names a site or module may rely on. The README holds the
same as a guide with examples.

## Distribution

- Private GitHub repository `sliusardev/hata`, `license: proprietary`. A site adds
  `repositories: [{"type": "vcs", "url": "git@github.com:sliusardev/hata.git"}]` (or
  `https://github.com/sliusardev/hata.git`) to its `composer.json` and gives Composer a read-only fine-grained GitHub
  token (repository access: `sliusardev/hata` only; permission Contents: read) in the site's `auth.json`
  (`composer config github-oauth.github.com <token>`, never committed) or in `COMPOSER_AUTH` on the server or in CI.
- Versions: no tags yet (`0.x` from the first client site on), so a site requires `dev-main` until then.
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

One published file, `config/hata.php` (tag `hata-config`), sections `admin`, `content`, `media`.
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

## Developing Hata

- The package is at `/Users/vitalii/Herd/hata`; the demo `/Users/vitalii/Herd/hata-demo` requires it through a
  `path` repository with `symlink: true` (`sliusardev/hata: *@dev`) and is served by Herd at https://hata.test.
  PHP, views and translations show at once; after `npm run build` (which rewrites `dist/`, commit it with the source
  change) run `php artisan vendor:publish --tag=hata-assets --force` in the demo.
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

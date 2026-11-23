# Hata

Hata (`sliusardev/hata`) is an admin panel and CMS for Laravel, installed as a Composer package. It gives a fresh
Laravel app sign-in (with 2FA and passkeys), users, roles and permissions, site settings, a media library, a
maintenance page, content types (pages, posts, categories, tags, and your own) with their public pages, and an
English and Ukrainian interface. Hata's fixes reach a site with `composer update`.

The contract behind this guide (every name and rule) is [docs/specs/package.md](docs/specs/package.md); the design
decisions are in [CLAUDE.md](CLAUDE.md).

## Requirements

- PHP 8.4+ with `intl`, and GD or Imagick (with WebP) for image optimization
- Laravel 13
- MySQL/MariaDB or SQLite
- No Node.js on the site: Hata's admin and site assets come prebuilt. No queue worker or cron is required either, so a
  site runs on shared hosting as well as on a VPS.

Hata brings Livewire 4, Flux (free; pinned to `~2.21.0` because its CSS is built into Hata's assets), Laravel Fortify
and spatie/laravel-permission as dependencies.

## Installing

### 1. Give Composer access to the private repository

Hata lives in the private GitHub repository `sliusardev/hata`. In the site's `composer.json`:

```json
"repositories": [
    {"type": "vcs", "url": "git@github.com:sliusardev/hata.git"}
]
```

With SSH access to GitHub (a deploy key on the server) that is enough. Otherwise use the HTTPS URL
`https://github.com/sliusardev/hata.git` and a token: create a fine-grained GitHub token with access to the
`sliusardev/hata` repository only and the permission **Contents: read-only**, then

```bash
composer config github-oauth.github.com <token>   # writes auth.json; keep it out of git (Laravel's .gitignore has it)
```

On a server or in CI, pass it as `COMPOSER_AUTH='{"github-oauth": {"github.com": "<token>"}}'` instead.

### 2. Require Hata and run the installer

On a fresh Laravel 13 app (`laravel new site`, no starter kit), with the database configured in `.env`:

```bash
composer require sliusardev/hata:dev-main    # until the first tagged release; then a version constraint
php artisan hata:install
```

To create the first super admin without prompts, set `ADMIN_NAME`, `ADMIN_EMAIL` and `ADMIN_PASSWORD` in `.env`
(remove the password after the install), or pass `--name`, `--email` and `--password`. `--no-migrate` skips the
migrations. The installer refuses to run while the config is cached (`php artisan config:clear` first).

What it does, each step safe to repeat:

- Adds `implements AdminUser` and `use IsAdminUser` to `app/Models/User.php`.
- Removes Laravel's `welcome` route and view (Hata's home page takes `/`).
- Adds `configureDefaults()` to `app/Providers/AppServiceProvider.php`: immutable dates, strict password rules in
  production and `DB::prohibitDestructiveCommands()` in production. These are your site's defaults; Hata does not
  depend on them.
- Makes `database/seeders/DatabaseSeeder.php` call `Hata\Database\Seeders\HataSeeder` (and drops the skeleton's Test
  User and `WithoutModelEvents`).
- Ensures `composer.json` → `post-autoload-dump` runs `vendor:publish --tag=hata-assets --force` right after
  `package:discover` (so `composer install` publishes the assets too), and `post-update-cmd` runs
  `vendor:publish --tag=laravel-assets --force` and `view:clear`.
- Publishes `config/hata.php` and the assets (`public/vendor/hata`), runs the migrations, syncs the permissions and
  roles, creates the super admin, stores the site's first content language, and creates the storage link.

It ends with a report: every step is `done`, `already done`, `skipped`, `failed` or `do by hand`. A file it cannot
follow (because you changed it) is left untouched, and the report prints the lines to add yourself. The command
exits with 1 when a step failed, and with 0 otherwise.

### 3. Web server

Uploaded images are static files under `/storage/media/` (after `php artisan storage:link`, which the installer runs;
on hosting without symlinks, use Admin → Site settings → Media → "Create the storage link", and until then images go
through the app, only slower). Hata's assets are static files under `/vendor/hata/`.

**Apache:** copy `vendor/sliusardev/hata/stubs/htaccess` to `public/.htaccess`. It is Laravel's file plus a week of
caching and `X-Content-Type-Options: nosniff` for images under `storage/media/`, and a year of
`Cache-Control: public, max-age=31536000, immutable` for Hata's assets under `vendor/hata/assets/`, whose names carry
a content hash (both rules assume the site is at the domain root). A site that keeps its own `.htaccess` copies the two
`mod_headers` blocks from the end of the stub.

**nginx:** add to the site's `server` block, before the generic `location /` (an `add_header` inside a `location`
replaces the ones inherited from the `server` block, so keep both lines):

```nginx
location ^~ /storage/media/ {
    add_header Cache-Control "public, max-age=604800";
    add_header X-Content-Type-Options nosniff;
    try_files $uri /index.php?$query_string;
}

location ^~ /vendor/hata/assets/ {
    add_header Cache-Control "public, max-age=31536000, immutable";
    try_files $uri =404;
}
```

The media fallback sends a request for a missing file to Laravel, so an address handed out before the link broke still
answers. Without these rules browsers still cache the files, because the file names change with every edit.

### Deploying

A deploy that pulls the code and installs from the lock file (`git pull && composer install --no-dev`) needs no
step for Hata's assets: Composer runs `post-autoload-dump` on every install and update, and its
`vendor:publish --tag=hata-assets --force` line (added by the installer) copies the built assets of the installed Hata
to `public/vendor/hata`. `composer update` also runs `post-update-cmd` (every package's assets again, and
`view:clear`). Without the publish the views print tags for hashed files that are not there, and the admin and the
site load without CSS and JavaScript.

What runs automatically is the publish (and `view:clear` on update). The deploy script runs the rest itself:

```bash
git pull
composer install --no-dev --optimize-autoloader
php artisan migrate --force
php artisan optimize          # caches config, routes, views and events; never caches the stored site settings
```

`php artisan db:seed --force` is needed only when an update adds permissions (safe to repeat), and a queue worker is
restarted with `php artisan queue:restart`.

### Shared hosting without SSH

Install locally (or in CI) with `composer install --no-dev`, upload the code with `vendor/` and `public/vendor/hata`,
set `ADMIN_*` in `.env`, run `php artisan migrate --force && php artisan db:seed --force` from the hosting panel, then
remove `ADMIN_PASSWORD`. Later migrations can run from Admin → Maintenance. With `QUEUE_CONNECTION=sync`, long media
tasks run in the open admin page, or with `php artisan media:optimize` / `media:move-disk`. With a real queue, run a
worker and use one shared cache store (database, redis or file, not `array`): media jobs use cache locks.

## Updating

```bash
composer update sliusardev/hata   # post-update-cmd republishes public/vendor/hata and clears compiled views
php artisan migrate               # or Admin → Maintenance → Run migrations
php artisan db:seed --force       # only when an update adds permissions; safe to repeat (or permissions:sync)
```

Keep the `post-autoload-dump` publish and both `post-update-cmd` lines: without the publish the admin runs old CSS/JS
against new views (or none at all after `composer install`), and without `view:clear` compiled views can keep stale
component names. After changing `.env` or `config/hata.php` on a site that
caches its config and routes, run `php artisan optimize` (or Admin → Maintenance → Rebuild caches).

## Configuration

`config/hata.php` (published by the installer, or `php artisan vendor:publish --tag=hata-config`) has three sections:

- `admin`: the admin path, interface languages, registration, the default role of registered users, the display
  timezone, colors, logos, the initial admin (`ADMIN_*`).
- `content`: the content types, the trash period, reserved slugs.
- `media`: the disk, optimization, upload limits, thumbnails.

Keys missing from your file keep Hata's defaults, so a file published by an older Hata keeps working. Lists
(`content.types`, `content.reserved_slugs`) and `admin.interface_locales` replace Hata's value as a whole. Values saved
in Admin → Site settings override the file and `.env`.

### Admin path

The admin answers at `/admin` unless the site picks another path, so bots probing `/admin` find nothing. Set
`HATA_ADMIN_PATH=cp-7f3k` in `.env` (one segment of lowercase letters, digits and hyphens; not a language code or an
address another route uses, otherwise Hata refuses to register its routes and names the reason), then rebuild the
route cache with `php artisan optimize`. The `/admin` slug is then free for content. It is config only, never a site
setting, so a wrong value can't lock you out.

### Languages

The **interface** (admin, account settings, sign-in) is in English and Ukrainian; each user picks one under Settings →
Language. To add one, list it in `config/hata.php` → `admin.interface_locales` (for example `'pl' => 'Polski'`) and
translate every file of `vendor/sliusardev/hata/resources/lang/en` into `lang/vendor/hata/pl/`. To change a single line,
put just that key in `lang/vendor/hata/<locale>/<file>.php`. Laravel's validation, password and pagination lines are
read Laravel first, then Hata, then your `lang/`, so your own line wins.

The **content** languages of the public site are a separate list, chosen in Admin → Site settings → Languages from a
built-in catalogue. The installer stores the interface default as the first one. Details:
`docs/specs/languages.md`.

## Using the admin

- **Roles and permissions:** the system roles `super-admin`, `admin`, `editor` and `author` are created by the seeder.
  Code checks permissions (`can('users.edit')`), never role names; a super admin passes every check. Protection rules
  stop removing the last super admin, changing your own roles, deleting yourself and granting what you don't have.
- **Registration** is off by default. Turn it on in Admin → Site settings (or `ADMIN_REGISTRATION_ENABLED=true`);
  registered users get the role chosen there, none by default.
- **Site settings** hold the site name, languages, content types (on and off), timezone, email (SMTP), the light and
  dark logo (picked from the media library), the color scheme and media storage.
- **Media library:** rasters are optimized to WebP and served as static files; SVG, documents and kept originals stay
  private and go through the app. Upload limits default to 30 MB per image and 20 MB per document; set PHP's
  `upload_max_filesize` and `post_max_size` to at least `32M`. AVIF and HEIC need an Imagick that decodes them.
  Optional S3-compatible storage (S3, R2, B2, Spaces, MinIO) is set up under Site settings → Media, and existing
  files move between disks with Admin → Media → Move or `php artisan media:move-disk s3|public`. Details:
  `docs/specs/media.md`.
- **Maintenance** (super admin only; the permission `maintenance.run` is reserved): run pending migrations, one of
  the site's seeders, clear or rebuild the caches, read and delete log files; every run is journaled. If "Rebuild
  caches" ever leaves the site unable to open, delete the files in `bootstrap/cache/` (keep `.gitignore`).

Commands: `hata:install`, `admin:create`, `permissions:sync [--prune]`, `make:content-type`, `content:check`,
`content:prune-trash`, `media:optimize`, `media:move-disk`, `media:prune-deleted`.

## Extending Hata

Extend through these points instead of editing `vendor/` or copying Hata's files; everything here keeps receiving
Hata's fixes. A module package (such as a shop) uses the same API from its service provider.

### Content types

Pages, posts and their taxonomies (categories, tags) are content types. Add your own:

```bash
php artisan make:content-type Recipes          # flat, at /recipes
php artisan make:content-type Docs --tree      # a tree, like pages
php artisan permissions:sync
php artisan content:check                      # no existing page is hidden by the new base
```

The command writes `app/ContentTypes/RecipesType.php`, adds it to your `config/hata.php` → `content.types` (publishing
the file first if needed) and adds English lines to `lang/<locale>/content.php` and `lang/<locale>/permissions.php`
for every interface language, for you to translate. Then give the new permissions to roles on the Roles page.

A type's extra fields are its `fields()` method; no migration is needed (values are stored as JSON on the record):

```php
use Hata\Support\Content\Fields\{Date, Gallery, MediaFile, Number, RichText, Select, Text, Textarea, Toggle};

public function fields(): array
{
    return [
        Text::make('subtitle'),
        Number::make('price')->required()->shared(),   // shared: one value for every translation
        Select::make('size')->options(['s' => 'Small', 'l' => 'Large']),
        Gallery::make('gallery'),
    ];
}
```

Add each field's label to `lang/<locale>/content.php` → `fields`. A taxonomy is a type whose `taxonomyOf()` returns
the types it files (`['posts']`); that one method adds a card to their form, a column and filter to their list, a
menu item and a public page per term. A type's labels are read from your `lang/` files; a module's type returns its
own namespace from `translationPrefix()` (`'shop::'`). Types are switched off without being removed in Site settings
→ Content types. Details: `docs/specs/content.md`.

### Permissions

```php
use Hata\Support\Permissions\PermissionRegistry;

// in a service provider's register() or boot()
$this->app->make(PermissionRegistry::class)->register('reports', ['view', 'export']);                  // site: lang/*/permissions.php
$this->app->make(PermissionRegistry::class)->register('shop', ['view', 'edit'], 'shop::permissions');  // module
```

Labels are `groups.<group>` and `items.<group>.<action>` in that translation file (the site's `permissions.php` by
default). Run `php artisan permissions:sync` (`--prune` removes permissions no longer registered).

### Admin pages, menu and assets

```php
// routes: the admin middleware, path and names
Route::middleware(['web', 'auth', 'verified', 'can:admin.access'])
    ->prefix(config('hata.admin.path'))
    ->name('admin.')
    ->group(function () {
        Route::livewire('reports', 'reports.index')->middleware('can:reports.view')->name('reports.index');
    });
```

```php
use Hata\Support\Admin\{AdminAssets, AdminMenu, AdminMenuItem};

// in a service provider's boot()
$this->app->make(AdminMenu::class)->add(new AdminMenuItem(
    key: 'reports',
    label: 'reports.menu',                 // a translation key or plain text
    icon: 'chart-bar',                     // a Flux icon
    route: 'admin.reports.index',
    permission: fn ($user) => $user->can('admin.access') && $user->can('reports.view'),
    group: 'administration',               // platform, content, administration, account, or your own group()
    order: 50,
));

// a module's own prebuilt CSS/JS (published under public/vendor/shop), printed after Hata's in the admin <head>
$this->app->make(AdminAssets::class)->add('vendor/shop', ['resources/css/admin.css']);
```

A full-page Livewire component uses `#[Layout('hata::layouts.app')]`; a Blade page wraps itself in
`<x-hata::layouts.app :title="__('reports.title')">`. Hata's components are yours to use:
`<x-hata::confirm-modal>`, `<x-hata::admin.row-actions>`, `<x-hata::admin.empty-state>`, `<x-hata::admin.rich-text>`,
`<x-hata::flash-toast>`, `<x-hata::site-logo>`, the layouts `<x-hata::layouts.app>`, `<x-hata::layouts.auth>` and
`<x-hata::layouts.focus>`, and the Livewire media picker `hata::media-picker`. Menu details:
`docs/specs/admin-menu.md`.

### Theme and view overrides

The public pages are Blade views: `hata::layouts.site`, `hata::site.entry` (a record), `hata::site.list` (a type's
list), `hata::site.term` (a term) and `hata::welcome` (the home page). Laravel lets a site override any of Hata's Blade
views, components and layouts by putting a file at the same path under `resources/views/vendor/hata/`, for example
`resources/views/vendor/hata/site/entry.blade.php`.

**An overridden view stops receiving Hata's fixes:** your copy is used from then on, whatever Hata changes in its own.
Prefer the extension points above; override the public `site/*` and `layouts/site` views for a theme, and admin views
only with a good reason. Livewire pages (the admin's screens under `resources/views/livewire`) cannot be overridden
this way.

### Seeders and migrations

Your `DatabaseSeeder` calls `HataSeeder` (the installer writes it) and then your own seeders; don't wrap it in
`WithoutModelEvents`. Hata's migrations load from the package; add your own as usual. Don't publish Fortify's
two-factor migration or laravel/passkeys' migration: Hata already creates those columns and that table, and the
published copies would fail.

### What a site accepts by installing Hata

Hata is a CMS, not a library, so a few site-wide mechanisms come with it: its routes (`/`, the content fallback, which
any route of yours beats, sign-in, `/settings`, the admin, media), `SetLocale` on the `web` group, the content lookup
before the session starts, site settings written over `app.name`, `app.locale`, `mail.*` and the S3 disk, a handler
for missing translation keys (a site that needs its own calls
`Hata\Support\Translation\VendorTranslations::translate()` from it), global validation attribute names, a morph map
for its models, and a super admin who passes every permission check. It also sets these config values over the
site's own files: Fortify's `features`, `views`, `home`, `middleware`, `limiters` and `lowercase_usernames` (a site's
`config/fortify.php` values for them are ignored), `images.default` (imagick or gd) and Livewire's
`temporary_file_upload.rules`. The site's user model gets `Hata\Policies\UserPolicy` as its exact policy, which beats
an `App\Policies\UserPolicy` Laravel would guess and a `#[UsePolicy]`: a site that needs its own extends
`Hata\Policies\UserPolicy` and registers it with `Gate::policy()` in its `AppServiceProvider::boot()` (it runs after
Hata's). The full list:
[docs/specs/package.md](docs/specs/package.md#what-a-site-accepts-by-installing-hata).

## Developing Hata

This repository is the package; `/Users/vitalii/Herd/hata-demo` is the app to look at it in a browser. The demo
requires Hata through a path repository with a symlink:

```json
"repositories": [{"name": "hata", "type": "path", "url": "../hata", "options": {"symlink": true}}],
"require": {"sliusardev/hata": "*@dev"}
```

Herd serves it at https://hata.test. PHP, views and translations show there at once. `php artisan migrate:fresh --seed`
in the demo rebuilds it with the `ADMIN_*` super admin from its `.env`.

```bash
composer test           # Pint (check), PHPStan level 7, Pest on Orchestra Testbench (SQLite in memory)
composer lint           # format with Pint
npm ci && npm run build # rebuild dist/ after changing resources/css or resources/js; commit dist/ with the change
```

After a build, run `php artisan vendor:publish --tag=hata-assets --force` in the demo. To run the suite against MySQL,
create an empty database and override the connection (never at the same time as the SQLite run; `pest --parallel`
does not work in this setup):

```bash
DB_CONNECTION=mysql DB_HOST=127.0.0.1 DB_DATABASE=hata_testing DB_USERNAME=root DB_PASSWORD= vendor/bin/pest
```

GitHub Actions runs Pint and PHPStan, checks that `npm run build` leaves `dist/` unchanged (PHP 8.5), and runs the
suite on PHP 8.4 and 8.5 against SQLite and MySQL 8, on pushes to `main` and on pull requests.

# Hata as a Composer package

Status: written 2026-11-23, awaiting the user's approval. Builder check done; its questions are answered below.

## Intent

Today Hata is a Laravel application built from the Livewire starter kit: the whole admin panel (auth, users, roles,
site settings, media library, maintenance, content types, the public content pages) lives in `app/`, `resources/`,
`routes/`, `config/`, `database/` of one project. The user wants to build client sites on it and keep those sites
up to date:

> Install an empty Laravel, then install the hata package. Fixes arrive with every `composer update`.

No site runs on Hata yet, so nothing has to stay compatible: names, namespaces, config keys, migrations and routes
can all change now. That stops being true at the first client site.

What a client site will add on top of Hata (the user's words: "своі типи контенту, тема сайту і модулі типу
магазину ну і власні сідери, міграції тощо. Все поверх хати"):

- its own content types,
- its own theme for the public site,
- modules such as a shop; the shop will be used on several sites, so it will be a separate package (`hata-shop`),
  built later,
- its own seeders, migrations and anything else a Laravel app has.

## Goals

1. `composer require <vendor>/hata` + `php artisan hata:install` on a fresh Laravel 13 gives today's Hata: login,
   admin, settings, media, maintenance, content, public pages, in English and Ukrainian.
2. `composer update` brings Hata's fixes, code, views, translations, migrations and built assets, with no manual
   step on the site (beyond `php artisan migrate`, which the maintenance page can run).
3. A site extends Hata without editing it: its content types, its theme, its admin sections (menu, permissions,
   pages, assets), its seeders and migrations.
4. A module package (later `hata-shop`) uses the same extension API as code in the site's `app/`. Hata's own
   sections register through that API, so it is exercised by real code before any module exists.
5. The user keeps developing and checking Hata in a browser through Herd, as today.
6. Everything that works today keeps working, verified by the existing test suite (moved to the package) and by a
   browser pass on the demo site.

## Non-goals

- Building `hata-shop`, themes, or Part 3 (head tags, sitemap, home page setting). Part 3 comes right after this.
- A settings-section registry for modules, module migrations tooling, a module marketplace. Only what goal 3 needs
  for the kinds of extension listed above; more API arrives with `hata-shop`.
- New features or behavior changes. This is a move, plus the registries needed to make the move clean.
- Keeping the current app's git history in the demo app.

## Anti-goals (would be a failure even if it works)

- The site has to run `npm` to get a working admin.
- An update silently overwrites something the site customized, or a site has to copy (publish) Hata's views or code
  to change a detail and then stops receiving fixes for it.
- Hata changes global defaults of the host app that are not Hata features (e.g. `Date::use(CarbonImmutable)`,
  password defaults, `DB::prohibitDestructiveCommands()`) — those stay the site's decisions. Hata is a CMS, not a
  library: installing it means the site takes Hata's site-wide mechanisms (see "What a site accepts by installing
  Hata"); those are not this anti-goal.
- Extra per-request cost compared with today (standing rule: performance first). E.g. assets served through PHP
  instead of as static files.
- The admin looks or behaves worse than today in any screen.

## Approaches considered (picked: A)

- **A. Two repositories: `hata` (the package) and `hata-demo` (an app).** The current repository becomes the package,
  history kept. `hata-demo` is a fresh Laravel that requires Hata exactly as a client site would, through a Composer
  `path` repository (symlink), so edits in the package show at once. Herd serves the demo; tests live in the package
  on Orchestra Testbench. `hata-shop` will be a third repository. Trade-off: a change touching both is two commits;
  in return the demo proves the "empty Laravel + hata" path from day one. **The user picked this.**
- B. Monorepo (app + `packages/hata`): Composer cannot install a package from a subdirectory, so publishing needs a
  subtree-split tool after every commit. Extra machinery while no site exists. Rejected.
- C. Package only, with a Testbench Workbench app (`vendor/bin/testbench serve`): Herd cannot serve it, which breaks
  the user's browser-check workflow. Rejected.

## Design

### Repositories and folders

- **Package repo**: stays at `/Users/vitalii/Herd/hata` (keeps git history, CLAUDE.md, docs, Claude memory path).
  Layout: `src/` (namespace `Hata\`), `resources/{views,css,js,lang}`, `database/{migrations,factories,seeders}`,
  `config/`, `routes/`, `dist/` (built assets, committed), `stubs/` (installer stubs), `tests/` (Pest on Testbench),
  `workbench/` (Testbench's app for tests: a `User` model with the Hata trait, its factory). Herd will park this
  folder as `hata.test`; with no `public/index.php` it simply shows nothing.
- **Demo repo**: `/Users/vitalii/Herd/hata-demo`, a fresh `laravel new` (no starter kit), Composer `repositories:
  [{type: path, url: ../hata, options: {symlink: true}}]`, `require "<vendor>/hata": "*@dev"`, then `hata:install`.
  Served by Herd; my call: `herd link hata` inside it so the address stays `hata.test` (fallback `hata-demo.test`).
  The demo is installed **fresh** (the user's decision): a new database through `hata:install`, test content and
  media created again by hand. Only `.env` values worth keeping (MinIO/S3 settings, mail) are copied over. No
  conversion of the current local database, so no one-off code for stored class names or migration names. The demo
  also holds Laravel Boost (AGENTS.md, MCP) for development.

### Package name, namespace and distribution

- Composer `sliusardev/hata` (the GitHub repository `sliusardev/hata`, already pushed). PHP namespace `Hata\`;
  view, translation, Livewire and Blade-component namespace `hata`; config key `hata`.
- **Private repository** (the user's decision; *superseded on 2026-11-24 by decision 73: the repository is public under MIT and needs no token*). A site installs Hata through
  `repositories: [{type: vcs, url: git@github.com:sliusardev/hata.git}]` (or the HTTPS URL) and a read-only
  fine-grained GitHub token in the site's `auth.json` / `COMPOSER_AUTH`. The README documents this; `license` is
  `proprietary`. Making it public later needs no code change. `hata-shop` will be distributed the same way.
- GitHub Actions on a private repository has a monthly minutes quota; the CI matrix stays as today (4 jobs) and
  runs on push to `main` and on pull requests only.

### Service provider and what moves out of the host

`Hata\HataServiceProvider` (auto-discovered) takes over everything that today sits in `AppServiceProvider`,
`ContentServiceProvider`, `FortifyServiceProvider` and `bootstrap/app.php`:

- routes (`routes/web.php`, `admin.php`, `settings.php` of the package), the `Route::localized()` macro,
  `dashboard` redirect, media routes, the content fallback route (it is `->fallback()`, so host routes always win);
- middleware: `SetLocale` appended to the `web` group, `LookUpContent` placed before `StartSession` in the middleware
  priority, `EnsureRegistrationIsEnabled` in Fortify's middleware;
- Fortify: features, views, `home` = `/dashboard`, actions — configured at runtime so the host needs no
  `config/fortify.php` edits;
- spatie/laravel-permission: Hata's `Role` model set as `permission.models.role` at runtime; the `Gate::before`
  super-admin rule;
- `SiteSettings` applied over config at boot, `Queue::before` re-apply, keeping settings out of `config:cache`
  (decision 54), image driver selection, the vendor-literal translation map (decision 7), scheduler entries
  (`media:prune-deleted`, `content:prune-trash` daily), console commands, Livewire namespace
  (`Livewire::addNamespace('hata', …)`; Livewire 4 has it), Blade component namespace, Flux overrides (the five files
  in `resources/views/flux`: icons become `hata::` components; `navlist/group` is registered as an anonymous path for
  `flux` from the package — the builder checks Flux's lookup order, else it becomes a `hata::` component too).

What stays the host's: `Date::use(CarbonImmutable)`, `Password::defaults()`, `DB::prohibitDestructiveCommands()`.
The installer writes them into the host's `AppServiceProvider` (as the starter kit does) — they are defaults, not
Hata's. Hata's code must not depend on them: anything that relies on immutable dates uses `CarbonImmutable`
explicitly, and the package's tests run with Laravel's default (mutable) dates to prove it.

### What a site accepts by installing Hata

These site-wide mechanisms are Hata features and move as they are (my call, told to the user):

- `SetLocale` on the whole `web` group (and as a Livewire persistent middleware): the site's theme routes and module
  admin pages need the same language handling as Hata's own.
- `LookUpContent` in the middleware priority before `StartSession` (decision 59).
- Site settings written over `app.name`, `app.locale`-related keys and `mail.*` at boot and before every queued job:
  this is what the Site settings page edits.
- `Lang::handleMissingKeysUsing()` for the vendor-literal map (decision 7). Laravel keeps one handler; the README
  says a site that needs its own must call Hata's from it.
- Fortify's `features`, `views`, `home`, `middleware`, `limiters` and `lowercase_usernames`, set over the site's
  `config/fortify.php` (Hata's sign-in flows depend on them); `images.default` (imagick or gd) and Livewire's
  `temporary_file_upload.rules` (the media limits).
- An exact `Gate::policy()` mapping of the configured user model to `Hata\Policies\UserPolicy`, which beats a guessed
  `App\Policies\UserPolicy` and `#[UsePolicy]`; a site that needs its own extends Hata's and registers it in its
  `AppServiceProvider::boot()`, which runs after Hata's. (Added after the final review; the full list is in
  `docs/specs/package.md`.)

### Stored class names

Hata registers a morph map for its polymorphic models (`entry`, `setting`, and any other model that appears in
`media_usages.usable_type` or another `*_type` column), so the database never stores `Hata\…` class names and a
later rename does not break data. It uses `Relation::morphMap()`, not `enforceMorphMap()`, so the host's own
polymorphic relations are not forced to register.

### The user model

The host owns `App\Models\User` (and `auth.providers.users.model`). Hata ships a trait (`Hata\Models\Concerns\
HasHata` or similar: roles, search index, locale preference, passkeys, 2FA, `HasLocalePreference`) and an interface if
needed; the installer adds them to the host's `User`. Hata's code never names `App\Models\User`: it resolves the
model class from `auth.providers.users.model` (70 files reference it today). Hata's columns on `users` (locale,
search_index, 2FA, …) come from Hata's migrations as `alter table` migrations; the host's own `create_users_table`
stays Laravel's.

### Config

One published file `config/hata.php` holding what a site may change (`types` of content, registration default,
initial admin, media disk, etc.), merged with the package's defaults. Data that is not configuration (the language
catalogue `config/languages.php`, permission groups, role defaults) stays inside the package, not published. Hata
reads `hata.*` keys; no more top-level `admin.*`, `media.*`, `content.*` keys that could collide with a host's own
config files. Site settings keep overriding these keys at runtime as today. Laravel's own configs (`filesystems`,
`queue`, `cache`, `mail`, `auth`) stay the host's; Hata reads them as today.

### Translations

Package files under `resources/lang/{en,uk}` loaded as `hata::`; every `__('users.x')` becomes `__('hata::users.x')`.
A site overrides a line in `lang/vendor/hata/{locale}/…`, or adds an interface language there. Strings of the site's
own content types and modules live in the site's (or module's) own translation files. `TranslationParityTest` moves
with the package and checks the `hata::` keys.

### Views, Livewire pages, Blade components

- Views load as `hata::…`; a site may override one through Laravel's standard `resources/views/vendor/hata/…`, but
  the documented extension points (below) are the supported way; overriding an admin view means that view stops
  receiving fixes, and the README says so.
- Livewire SFC pages: `hata::admin.users.index` etc.; their layouts set explicitly (`hata::layouts.app`, `…focus`).
- Blade components: `<x-hata::confirm-modal>`, `<x-hata::admin.row-actions>`, `<x-hata::admin.empty-state>`, media
  picker, etc. These are public API for modules.

### Assets

- The admin CSS/JS (Tailwind 4 + Flux CSS, Tiptap, cropper, uploader, passkeys) and the public site's `site.css` are
  built inside the package (`vite build` with `base: /vendor/hata/`) into `dist/`, committed, so a site never runs
  `npm` for Hata.
- They are published to `public/vendor/hata` under the `laravel-assets` tag. Laravel's skeleton already runs
  `vendor:publish --tag=laravel-assets --force` on `post-update-cmd`, so every `composer update` refreshes them; the
  installer checks that line is in the host's `composer.json`. They are static files (no PHP per request); URLs carry
  the Vite manifest's hashed names. *Amended after the final review:* a deploy that runs `composer install` from the
  lock file never runs `post-update-cmd`, so the installer also adds `@php artisan vendor:publish --tag=hata-assets
  --force --ansi` to `post-autoload-dump`, after `package:discover` (it runs on install and update).
- Fonts (today Bunny's Instrument Sans through the Vite plugin) are bundled into `dist/` or loaded the same way the
  build does now; the builder picks, keeping no third-party request the site does not have today.
- Flux's CSS is compiled into Hata's admin CSS at build time, so Hata pins `livewire/flux` to the minor it was built
  with (`~2.21.0`), and every Hata release is built against the Flux it allows.
- Module assets: a module's admin pages may use Tailwind classes that Hata's CSS lacks. A module ships its own
  prebuilt CSS/JS and registers it (`Hata::adminAssets(...)` or a registry), and the admin layout includes the
  registered files. This is the one asset API built now (goal 4), exercised by a test fixture, not by a real module.
- The site's theme builds its own CSS with the site's own Vite; the themes stage defines how. Until then the default
  public views use Hata's prebuilt `site.css`.

### Extension points (the API a site or a module uses)

- **Content types**: as today (`ContentType` classes, `config/hata.php` → `types`, registered by Hata).
  `php artisan make:content-type` writes into the site: the class to `app/ContentTypes` (namespace
  `App\ContentTypes`), the config entry to the site's `config/hata.php`, the strings to the site's `lang/` files.
  The kit types (pages, posts, categories, tags) stay in the package and are listed in the default config.
- **Admin menu**: a registry (`AdminMenu`) of groups and items with label, icon, route, permission and order. Hata's
  own sections (dashboard, content types, media, users, roles, settings, maintenance) register through it; the
  sidebar renders the registry. Today only content types are dynamic.
- **Permissions**: `PermissionRegistry::register()` as today; labels from the registering package's translations.
- **Admin pages**: a module defines its routes with Hata's admin route group (a helper or documented middleware stack
  `auth, verified, can:admin.access`, prefix `admin`, name `admin.`) and its pages use `hata::layouts.app` and Hata's
  Blade components and base classes.
- **Admin assets**: the registry above.
- **Theme**: public views (`site/*`, `layouts/site`, the home page) resolve through the site first: a site's
  `resources/views/vendor/hata/site/...` already wins by Laravel's rules; the themes stage may replace this with a
  proper theme registry. Not designed further here.
- **Seeders**: the host's `DatabaseSeeder` calls `Hata\Database\Seeders\HataSeeder` (permissions sync, roles, the
  super admin from env), written there by the installer. The maintenance page keeps discovering the host's seeders.
- **Migrations**: Hata's migrations load from the package (`loadMigrationsFrom`); the site adds its own as usual.

### Installer (`php artisan hata:install`)

Idempotent, prints what it did and what it left: publishes `config/hata.php` (not spatie's migration: Hata ships spatie's tables, skipped when they exist); adds
the trait to `App\Models\User` (edits the file by its starter shape, else prints the lines, like the content-type
generator); removes Laravel's `welcome` route and view (Hata registers `home`); adds the host defaults to
`AppServiceProvider`; ensures the `laravel-assets` publish in `post-update-cmd`; publishes assets; runs migrations;
syncs permissions; creates the super admin (asks, or from `ADMIN_*` env); points at `storage:link` and the
`.htaccess`/nginx caching snippet from the README.

### Migrations

Since nothing is installed, Hata's migrations may be rewritten freely now: Laravel's own (users, cache, jobs) stay
the host's, Hata's become package migrations, and edited-in-place history can be squashed. The
`import_branding_logos_into_media` and `rewrite_local_media_storage_setting` data migrations only served the one
local install and are dropped (the demo is installed fresh). From the first release on, a shipped migration is
never edited.

### Tests and CI

The suite (118 files) moves to the package on Orchestra Testbench with Pest (`workbench/` provides the User model and
factory). `composer test` stays: Pint, PHPStan level 7 (Larastan on Testbench), tests; CI matrix PHP 8.4/8.5 ×
SQLite/MySQL as today. The demo repo has no suite of its own; my call: one smoke test there (install ran, admin and
home answer) is optional.

### Documentation

CLAUDE.md, the decisions list and `docs/specs/*` stay in the package repo, updated for the new paths and names; a
decision records this move. The README becomes the package's install and extension guide. The demo gets a short
CLAUDE.md pointing to the package. Hata may ship Laravel Boost guidelines (`resources/boost/guidelines/`) so AI agents
on client sites learn its extension API — my call: yes, short, since the user works with agents.

### Versioning

Semver tags from the first client site; `0.x` until then. Not designed further.

## Constraints

- PHP 8.4+, Laravel 13, Livewire 4 SFC, Flux free (no Pro), as today.
- No new dependency without the user's approval; this project adds `orchestra/testbench` (dev; Testbench's Pest
  integration as needed) — approved together with this design.
- Performance: no extra per-request work; assets static; the per-view query budget of public pages (decision 59)
  still asserted.
- Public pages never use Livewire (standing rule).

## Left to the builder

- Order of work and how to keep a green state in between (e.g. build the demo first, then move code in slices).
- The exact name of the user trait, the registry classes and the admin route helper.
- How Flux overrides are registered from a package.
- Font bundling.
- Which config keys go into `config/hata.php` vs stay internal, following the rule above.
- Whether the content-type generator's target folder is `app/ContentTypes` or mirrors today's
  `app/Support/Content/Types`.

# Hata as a Composer package — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Turn the Hata application into the Composer package `sliusardev/hata` that a fresh Laravel 13 installs with `composer require` + `php artisan hata:install`, plus a demo app `hata-demo` that uses it the way a client site will.

**Architecture:** The repository `/Users/vitalii/Herd/hata` becomes the package (`src/` → `Hata\`, views/translations/Livewire/Blade under `hata::`, one published `config/hata.php`, built admin assets in `dist/` published under `laravel-assets`). Tests run on Orchestra Testbench with a `workbench/` app. A separate repository `/Users/vitalii/Herd/hata-demo` requires the package through a Composer `path` repository (symlink) and is served by Herd. Work happens on branch `package` in the hata repo.

**Tech Stack:** PHP 8.4, Laravel 13, Livewire 4.4 (SFC), Flux 2.21 free, Fortify, spatie/laravel-permission 8, Pest 5, Orchestra Testbench (version for Laravel 13), Larastan level 7, Vite (vite-plus) + Tailwind 4.

**Spec:** `docs/superpowers/specs/2026-11-23-hata-package-design.md` (approved 2026-11-23). Read it before any task.

## Global Constraints

- Composer name `sliusardev/hata`, `"type": "library"`, `"license": "proprietary"`; private GitHub repository `sliusardev/hata` (git remote host alias `github.com-sliusardev`).
- PHP namespace `Hata\`; view, translation, Livewire and Blade-component namespace `hata`; config key `hata`.
- PHP `^8.4`, `laravel/framework ^13`, `livewire/livewire ^4.4`, `livewire/flux ~2.21` (pinned to the minor the assets are built with), no `livewire/flux-pro`, no Pro components.
- The only new dependency is `orchestra/testbench` (dev), plus Pest/Larastan glue it needs. Drop `laravel/chisel`, `laravel/tinker`, `laravel/sail`, `laravel/pail`, `laravel/pao`, `laravel/boost` from the package's requirements (they are host/dev-app tools; Boost lives in the demo).
- Hata never names `App\Models\User` (resolve the model from `auth.providers.users.model`) and never sets `Date::use(...)`, `Password::defaults(...)` or `DB::prohibitDestructiveCommands(...)`.
- No extra per-request work: admin/site assets are static files under `public/vendor/hata`; public record pages keep the ≤ 5 queries budget (`PublicContentTest`); public pages never use Livewire.
- Every interface string in `lang/en` and `lang/uk` with key parity (now under `hata::`); Artisan output in English.
- No migration shipped before this branch needs to stay: rewrite freely now. From the first release on, a shipped migration is never edited (write this in CLAUDE.md).
- Commits end with `Co-Authored-By: <the implementing model's trailer>`. Never push without the user's word.
- The local DB writes (`migrate`, `permissions:sync`, seeding) of the demo are run by the user with `!` (the permission classifier refuses them from the agent).
- Workflow for UI-visible steps: the user checks in the browser on the demo before a task's work is called done ([[ui-first-then-tests]]).

## Review Focus

1. **`composer update` on a site must refresh `public/vendor/hata`.** If the site's `composer.json` lacks `vendor:publish --tag=laravel-assets --force` in `post-update-cmd`, the admin silently runs old CSS/JS against new views. Pinned by Task 11's `HostFiles::ensureAssetsPublishScript` tests and the installer's report line.
2. **A site's published `config/hata.php` from an older Hata lacks newer keys.** Nested defaults must still apply, while lists (`content.types`, `content.reserved_slugs`) set by the site replace the defaults, not merge by index. Pinned by Task 6's `HataConfig::merge` tests.
3. **Host routes vs Hata routes.** The host's own `/` (Laravel's `welcome`) overrides Hata's `home`; a host route registered later must still beat the content fallback. Pinned by Task 1's `HostRoutesTest` (host route wins over the content fallback) and Task 11's installer test (welcome route removed).
4. **`config:cache` / `optimize` on a site** must never freeze stored site settings or decrypted secrets into `bootstrap/cache/config.php` now that the provider lives in `vendor/` (decision 54). The existing maintenance tests move; Task 1 keeps them green under Testbench.
5. **Views and Livewire SFC pages compiled from `vendor/sliusardev/hata`** (a symlink in the demo, a real folder on a site) must render and keep island partial renders (decision 53) working; Blaze must not skip or double-compile them. Pinned by Task 4's namespace render test and the existing `MediaPartialRenderTest`; checked in the browser on the demo in Task 3 and Task 4.

---

## File structure (target)

```
hata/                               (package repo, branch `package`)
  composer.json                     sliusardev/hata, autoload Hata\ => src/, extra.laravel.providers
  src/                              everything from app/ (App\ → Hata\), except the User model
    HataServiceProvider.php         routes, middleware, Fortify, permissions, settings, Livewire/Blade/view/lang namespaces, scheduler, commands
    Hata.php                        static helpers: path(), userModel()
    Providers/ContentServiceProvider.php, Providers/FortifyServiceProvider.php (registered by HataServiceProvider)
    Support/Admin/AdminMenu.php, AdminMenuItem.php, AdminAssets.php     (new, Tasks 2 and 9)
    Support/Assets/HataAssets.php   (new, Task 2)
    Support/Config/HataConfig.php   (new, Task 6)
    Support/Install/HostFiles.php   (new, Task 11)
    Models/Concerns/IsAdminUser.php, Contracts/AdminUser.php            (new, Task 7)
    Console/Commands/InstallCommand.php                                   (new, Task 11)
  config/hata.php                   published part (admin, content, media)
  config/internal/languages.php, config/internal/permissions.php        not published
  database/migrations/, database/factories/ (Hata\Database\Factories), database/seeders/HataSeeder.php, RoleSeeder.php
  resources/views/{components,livewire,partials,site,...}, resources/lang/{en,uk}, resources/css, resources/js
  routes/web.php, admin.php, settings.php
  dist/                             built assets (committed), `dist/.vite/manifest.json`
  stubs/                            installer stubs
  tests/                            Pest on Testbench; tests/fixtures/host/ (Laravel 13 skeleton files for HostFiles tests)
  workbench/app/Models/User.php     (namespace App\Models, autoload-dev "App\\": "workbench/app/") + workbench/database/factories/UserFactory.php
  testbench.yaml, phpunit.xml, phpstan.neon, pint.json, package.json, vite.config.js
hata-demo/                          (new repo) fresh Laravel 13 + sliusardev/hata via path repository
```

---

### Task 1: The repository becomes a package; the suite runs green on Testbench

The big mechanical move. Behavior does not change; interim shortcuts (unprefixed views/translations, old config keys) are removed by Tasks 4–6.

**Files:**
- Move: `app/**` → `src/**` (rewrite `App\` → `Hata\` in namespaces and imports everywhere: src, config, database, routes, resources/views, tests), except `app/Models/User.php` → `workbench/app/Models/User.php` (keeps namespace `App\Models`) and `database/factories/UserFactory.php` → `workbench/database/factories/UserFactory.php` (namespace `Database\Factories` kept for the workbench).
- Move: `resources/lang` (or `lang/`) → `resources/lang`.
- Create: `src/HataServiceProvider.php`; merge `AppServiceProvider` into it; keep `ContentServiceProvider` and `FortifyServiceProvider` under `src/Providers/`, registered from `HataServiceProvider::register()`.
- Delete: `bootstrap/`, `artisan`, `public/index.php`, `public/.htaccess` (copy it to `stubs/htaccess` for Task 11), `storage/` skeleton, `routes/console.php` (schedule moves to the provider), `app/Providers/AppServiceProvider.php`, `.env.example`. Do **not** delete the working copy's `.env`, `database/database.sqlite`, `storage/app/**` (gitignored local data; the user decides about them after Task 3).
- Rewrite: `composer.json` (package), `phpunit.xml`, `phpstan.neon` (paths `src/ config/ database/ routes/`, Larastan on Testbench), `tests/TestCase.php`, `tests/Pest.php`, `testbench.yaml`.
- Create: `tests/Feature/Package/HostRoutesTest.php`.

**Interfaces:**
- Produces: `Hata\Hata::path(string $relative = ''): string` (absolute path inside the package root; tests and generators use it instead of `base_path()`/`resource_path()`/`app_path()`/`lang_path()` for package files).
- Produces: `Hata\HataServiceProvider` auto-discovered via `extra.laravel.providers`.
- Produces (interim, removed in Task 4): views added with `View::addLocation(Hata::path('resources/views'))`; Livewire namespaces `pages` → `resources/views/pages`, `layouts` → `resources/views/layouts` via `Livewire::addNamespace()`; unprefixed anonymous Blade components from `resources/views/components`.
- Produces (interim, removed in Task 5): translations via `$this->loadTranslationsFrom(Hata::path('resources/lang'))` (no namespace → added path).
- Produces (interim, removed in Task 6): `mergeConfigFrom` for `admin`, `content`, `languages`, `media`, `permissions`.

- [ ] **Step 1: Create branch `package`; record a baseline**

Run: `git switch -c package && php artisan optimize:clear && composer test`
Expected: green (≈1666 passed). Record the passed/skipped counts in the task report; Task 1 ends with the same counts minus tests deliberately removed (none in this task).

- [ ] **Step 2: Diff the Laravel config files against a Laravel 13 skeleton**

Run: `composer create-project laravel/laravel <scratchpad>/skeleton --no-install --no-scripts` then `diff -ru <scratchpad>/skeleton/config config`.
Every difference in a Laravel-owned file (`app`, `auth`, `cache`, `database`, `filesystems`, `fortify`, `logging`, `mail`, `permission`, `queue`, `services`, `session`) is classified in the task report as either (a) applied at runtime by `HataServiceProvider::register()` with `config([...])` (e.g. `app.available_locales`, spatie `permission.models.role` = `Hata\Models\Role`, Fortify `features`/`home`/`views`, anything Hata reads that Laravel lacks), or (b) a host decision left to the installer (Task 11). Then delete those Laravel-owned config files from the package; keep `admin`, `content`, `languages`, `media`, `permissions`.

- [ ] **Step 3: Write `HostRoutesTest`**

```php
it('lets a host route win over the content fallback', function () {
    Route::get('about', fn () => 'host')->middleware('web');   // registered after Hata's routes
    $this->get('/about')->assertOk()->assertSee('host');
});

it('answers a content address the host does not define', function () {
    // a published page at /contacts created with the Entry factory
    $this->get('/contacts')->assertOk();
});
```

- [ ] **Step 4: Move the code and wire the provider**

Do the moves listed under Files with `git mv`; rewrite namespaces with a script (keep it out of the repo). In `HataServiceProvider`:
- `register()`: runtime config from Step 2, `mergeConfigFrom` (interim), the singletons from `AppServiceProvider::register()`, `ContentServiceProvider`/`FortifyServiceProvider`.
- `boot()`: `loadRoutesFrom(Hata::path('routes/web.php'))` (web.php keeps requiring admin.php/settings.php); `pushMiddlewareToGroup('web', SetLocale::class)`; put `LookUpContent` before `StartSession` in the middleware priority (Laravel 13's `Kernel::addToMiddlewarePriorityBefore()`, or the documented equivalent; verify with search-docs); `loadMigrationsFrom`; commands; schedule via `callAfterResolving(Schedule::class, ...)` (`media:prune-deleted`, `content:prune-trash` daily); everything else from `AppServiceProvider::boot()` except `configureDefaults()`.
- `configureDefaults()` (CarbonImmutable, Password defaults, prohibitDestructiveCommands) moves to a `workbench/app/Providers/WorkbenchServiceProvider.php` so behavior under test is unchanged until Task 8.
- Flux overrides (`resources/views/flux/*`): register them for the `flux` namespace from the package so they win over Flux's stubs (e.g. `callAfterResolving(BladeCompiler::class, ...)` in `register()`), and add `it('renders hata\'s flux overrides')` asserting `<flux:navlist.group>` output contains a marker unique to Hata's version and `<flux:icon.layout-grid />` renders Hata's SVG path.

- [ ] **Step 5: Testbench harness**

`tests/TestCase.php` extends `Orchestra\Testbench\TestCase`, `protected $enablesPackageDiscoveries = true;`, registers `WorkbenchServiceProvider`, sets `auth.providers.users.model` = `App\Models\User`, calls `$this->withoutVite()` in `setUp()`, keeps `useKitContentTypes()` and `skipUnlessFortifyHas()`. Replace every `base_path()/resource_path()/app_path()/lang_path()` that points at package files in tests (TranslationParityTest, MediaPartialRenderTest's island markup scan, generator tests, etc.) with `Hata::path(...)`. `phpunit.xml` keeps SQLite `:memory:` as the default and reads `DB_*` from env (CI MySQL).

- [ ] **Step 6: Run the suite**

Run: `composer test`
Expected: Pint clean, PHPStan level 7 clean on `src/ config/ database/ routes/`, the same passed/skipped counts as Step 1 plus the new tests.

- [ ] **Step 7: Run on MySQL**

Run: `DB_CONNECTION=mysql DB_HOST=127.0.0.1 DB_DATABASE=hata_testing DB_USERNAME=root DB_PASSWORD= vendor/bin/pest` (create the scratch database first).
Expected: green.

- [ ] **Step 8: Commit** — `Turn the repository into the sliusardev/hata package on Testbench`

---

### Task 2: Built admin and site assets, published as static files

**Files:**
- Modify: `vite.config.js` (`build.outDir: 'dist'`, `base: '/vendor/hata/'`, manifest on, inputs `resources/css/app.css`, `resources/css/site.css`, `resources/js/app.js`, `resources/js/passkeys.js`; hot file `hot` at the package root), `package.json`, `resources/css/app.css` (`@source` paths relative to the package; Flux CSS from `vendor/livewire/flux/dist/flux.css` of the package's own vendor).
- Create: `src/Support/Assets/HataAssets.php`, `src/Support/Admin/AdminAssets.php`, `dist/**` (built, committed), `.gitattributes` (`/dist -diff`).
- Modify: `resources/views/partials/head.blade.php`, `layouts/site.blade.php`, `components/passkey-registration.blade.php`, `components/passkey-verify.blade.php` (replace `@vite`), favicon/apple-touch-icon links.
- Move: `public/favicon.ico`, `favicon.svg`, `apple-touch-icon.png` → `resources/static/` and copy into `dist/` by the build.
- Test: `tests/Feature/Package/AssetsTest.php`.

**Interfaces:**
- Produces: `HataAssets::tags(array $entries): Illuminate\Support\HtmlString` — renders with a **fresh** `Illuminate\Foundation\Vite` instance: `useBuildDirectory('vendor/hata')`, `useHotFile(Hata::path('hot'))`, so the host's own `@vite` and its manifest are untouched. Blade directive `@hataAssets([...])`.
- Produces: `HataAssets::url(string $path): string` for static files (favicons) → `asset('vendor/hata/'.$path)`.
- Produces: `AdminAssets::add(string $buildDirectory, array $entries, ?string $hotFile = null): void` and `AdminAssets::tags(): HtmlString` (rendered in the admin `<head>` after Hata's own tags). Singleton.
- Produces: publish tag `laravel-assets` (and `hata-assets`) mapping `Hata::path('dist')` → `public_path('vendor/hata')`.

- [ ] **Step 1: Write `AssetsTest`**

Tests: `it('renders hata tags from its own manifest without touching the host vite')` — with a fixture manifest in a temp build dir, assert the output contains `/vendor/hata/assets/app-` and that `app(Vite::class)->manifestHash()` (host) is unaffected / still points at `build`; `it('uses the hot file while the package dev server runs')`; `it('appends module assets after hata\'s')` — `AdminAssets::add('vendor/shop', ['resources/css/shop.css'])` appears after Hata's link in a rendered admin page; `it('publishes dist under laravel-assets')` — `vendor:publish --tag=laravel-assets` lists the `vendor/hata` target.

- [ ] **Step 2: Run, expect FAIL** — `vendor/bin/pest tests/Feature/Package/AssetsTest.php`

- [ ] **Step 3: Implement** the two classes, the directive, the view replacements, the publish tag. Fonts: keep the current visual result with no new third-party request (either bundle Instrument Sans files into `dist/` or keep the Bunny link exactly as today); say which in the report.

- [ ] **Step 4: Build** — `npm install && npm run build`; commit `dist/`. Expected: `dist/.vite/manifest.json` with the four entries; Tiptap and the cropper stay separate dynamic-import chunks.

- [ ] **Step 5: Run** — `composer test`. Expected: green. Remove `withoutVite()` from `TestCase` only if the committed `dist/` manifest makes it unnecessary; otherwise keep it.

- [ ] **Step 6: Commit** — `Ship built admin and site assets under vendor/hata`

---

### Task 3: The demo app

**Files (new repo `/Users/vitalii/Herd/hata-demo`):** a Laravel 13 skeleton (`composer create-project laravel/laravel hata-demo`, no starter kit), `composer.json` (`repositories: [{"type": "path", "url": "../hata", "options": {"symlink": true}}]`, `require "sliusardev/hata": "*@dev"`), `app/Models/User.php` (temporarily the workbench User with its traits; replaced by the trait in Task 7), `.env` (APP_URL, SQLite, plus the MinIO/S3 and mail values copied from `/Users/vitalii/Herd/hata/.env`), `CLAUDE.md` (three lines: what this repo is, that the code lives in `../hata`, how to run), Laravel Boost (`composer require laravel/boost --dev`, `php artisan boost:install`).

- [ ] **Step 1:** Create the skeleton and require the package; `php artisan vendor:publish --tag=laravel-assets --force`. Expected: `public/vendor/hata/.vite/manifest.json` exists.
- [ ] **Step 2:** Remove the skeleton's `welcome` route and view; add the host defaults (CarbonImmutable, Password defaults, prohibitDestructiveCommands) to `AppServiceProvider` as the starter kit has them.
- [ ] **Step 3:** Herd: `herd link hata` from `hata-demo` so it answers at `hata.test` (if Herd refuses because the parked `~/Herd/hata` folder holds the name, use `hata-demo.test` and say so). `php artisan storage:link`.
- [ ] **Step 4:** Ask the user to run with `!`: `php artisan migrate && php artisan permissions:sync && php artisan db:seed --class='Hata\Database\Seeders\RoleSeeder' && php artisan admin:create` (exact command names as they are after Task 1).
- [ ] **Step 5:** Smoke check, then hand to the user's browser: login, `/admin`, users, roles, media upload, a page create + its public address, settings, maintenance. Expected: everything renders with styles; record any finding as a task for the next steps instead of patching the demo.
- [ ] **Step 6:** `git init`, commit in hata-demo (no remote unless the user gives one).

---

### Task 4: Views, Livewire pages and Blade components under `hata::`

**Files:** all of `resources/views/**`, every `view(...)`/`@include`/`@extends`/`<x-...>`/`<livewire:...>`/`Route::livewire(...)`/`#[Layout(...)]` reference in `src/`, `routes/`, `resources/`, `tests/`; `src/HataServiceProvider.php`.

**Target names (public API for modules, write them into the spec in Task 12):**
- Livewire SFC pages and components live in `resources/views/livewire/**` (moved from `views/pages/**` and the two `⚡` components in `views/components/`), namespace `hata`: `hata::admin.users.index`, `hata::settings.profile`, `hata::media-picker`, `hata::translation-picker`.
- Blade views `hata::partials.…`, `hata::site.entry`, `hata::welcome`, `hata::dashboard`.
- Anonymous Blade components `<x-hata::confirm-modal>`, `<x-hata::admin.row-actions>`, `<x-hata::admin.empty-state>`, `<x-hata::admin.rich-text>`, `<x-hata::flash-toast>`, `<x-hata::site-logo>`, `<x-hata::app-logo>`, `<x-hata::app-logo-icon>`, `<x-hata::auth-header>`, … (every component in `views/components`).
- Layouts as components: `<x-hata::layouts.app>`, `<x-hata::layouts.auth>`, `<x-hata::layouts.focus>`; Livewire page layout `hata::layouts.app` (resolve how Livewire 4 accepts a namespaced component layout with search-docs; the test below proves it).

- [ ] **Step 1: Write `tests/Feature/Package/ViewNamespacesTest.php`**: `it('renders an admin page through the hata livewire namespace')` (GET `/admin/users` as a super admin → 200, sees `<x-hata::layouts.app>` markup marker); `it('does not leave unprefixed hata views behind')` — scan `resources/views`, `src`, `routes` for `view('` / `@include('` / `<x-` / `<livewire:` / `Route::livewire(` / `Layout('` whose name is a Hata view without the `hata::` prefix (allow `flux:`, `x-slot`); expect none.
- [ ] **Step 2: Run, expect FAIL.**
- [ ] **Step 3: Implement** — `loadViewsFrom(..., 'hata')`, `Blade::anonymousComponentNamespace(..., 'hata')` (or `anonymousComponentPath`), `Livewire::addNamespace('hata', viewPath: Hata::path('resources/views/livewire'))`; remove Task 1's interim view location and `pages`/`layouts` namespaces; rename all references.
- [ ] **Step 4: Run** — `composer test`; expected green, including `MediaPartialRenderTest` (update its file paths).
- [ ] **Step 5: Browser** — the user clicks through the demo (same list as Task 3 Step 5, plus the media library islands: tile click, box select, "Show more", details panel). Expected: no change from Task 3.
- [ ] **Step 6: Commit** — `Namespace Hata's views, Livewire pages and components as hata::`

---

### Task 5: Translations under `hata::`; type and permission labels from their owner

**Files:** `resources/lang/{en,uk}/*.php`; every `__('...')`/`trans`/`@lang`/`trans_choice` of a Hata key in `src/`, `resources/views/`, `routes/`; `src/Support/Translation/VendorTranslations.php` (targets → `hata::auth.vendor.*`, `hata::common.flux.*`); `src/Support/Content/ContentType.php`; `src/Support/Permissions/PermissionRegistry.php`; `resources/views/partials/admin/permission-matrix.blade.php`; kit types in `src/Support/Content/Types/*`; `tests/Feature/Localization/TranslationParityTest.php`; page titles (`#[Title('hata::…')]`, decision 6 still applies).

**Interfaces:**
- Produces: `ContentType::translationPrefix(): string` — default `''` (a site's or module's type: keys `content.types.{key}.*` in the site's own `lang/`), Hata's kit types return `'hata::'`. `label()`, `pluralLabel()`, `description()`, `createLabel()` etc. build `"{$prefix}content.types.{$key}.…"`.
- Produces: `PermissionRegistry::register(string $group, array $actions, string $translationPrefix = 'permissions'): static`, `registerMany(array $groups, string $translationPrefix = 'permissions'): static`, `groupLabel(string $group): string` (`__("{$prefix}.groups.{$group}")`), `permissionLabel(string $permission): string` (`__("{$prefix}.items.{$permission}")`). Hata's own groups and kit types register with `'hata::permissions'`; content types register with `"{$type->translationPrefix()}permissions"`.

- [ ] **Step 1: Tests** — update `TranslationParityTest` to scan `Hata::path('src')`, `Hata::path('resources/views')` for `hata::` keys and assert each exists in both languages; add to `tests/Feature/Content/ContentTypeLabelsTest.php`: `it('reads a site type\'s labels from the site\'s translations')` (fixture type with prefix `''`, `trans()->addLines(['content.types.products.label' => 'Products'], 'en')` → `label() === 'Products'`) and `it('reads kit type labels from hata::')`; `PermissionRegistryTest`: `it('labels a permission from its registering owner')` (register group `shop` with prefix `shop::permissions`, add lines to namespace `shop` → `permissionLabel('shop.view')` is that line; Hata's `users.view` reads `hata::permissions.items.users.view`); the existing "every permission has a translation" test asserts through `permissionLabel()` and ignores fixture groups.
- [ ] **Step 2: Run, expect FAIL.**
- [ ] **Step 3: Implement** — `loadTranslationsFrom(Hata::path('resources/lang'), 'hata')`, remove the interim path, rename keys.
- [ ] **Step 4: Run** — `composer test`; green.
- [ ] **Step 5: Browser** — switch the demo user's language to Ukrainian and back; check the login page, a Fortify message (wrong password), a Flux pagination string, the permission matrix labels. Expected: no raw keys anywhere.
- [ ] **Step 6: Commit** — `Namespace Hata's translations as hata:: and label types and permissions by their owner`

---

### Task 6: One published `config/hata.php`

**Files:** Create `config/hata.php` (sections `admin`, `content`, `media`: the site-facing keys of today's `admin.php`, `content.php`, `media.php`); `config/internal/languages.php` (the catalogue) and `config/internal/permissions.php` (groups, roles, reserved) loaded into `hata.languages` and `hata.permissions`, not published; `src/Support/Config/HataConfig.php`; every `config('admin.…'|'content.…'|'media.…'|'languages.…'|'permissions.…')` and `config([...])` write in `src/`, `resources/`, `routes/`, `tests/` → `hata.*`; `SiteSettings` key map (`admin.*` → `hata.admin.*`, `media.*` → `hata.media.*`; `app.*`/`mail.*` unchanged); `app.available_locales` → `hata.admin.interface_locales`; `tests/TestCase.php` (`useKitContentTypes` writes `hata.content.types`, `hata.permissions.roles`). Test: `tests/Unit/HataConfigTest.php`, `tests/Feature/Package/PublishedConfigTest.php`.

**Interfaces:**
- Produces: `HataConfig::merge(array $defaults, array $published): array` — associative arrays merge recursively; a list (`array_is_list`) in `$published` replaces the default list entirely.
- Produces: publish tag `hata-config` → `config_path('hata.php')`. `HataServiceProvider::register()` sets `config(['hata' => HataConfig::merge(require Hata::path('config/hata.php') + internal files, config('hata', []))])` instead of `mergeConfigFrom` (works with a cached config too: when `app()->configurationIsCached()`, do nothing).

- [ ] **Step 1: Tests** — `HataConfigTest`: `it('fills nested keys a published file lacks')` (`['media' => ['s3' => ['bucket' => null]]]` defaults + published `['media' => []]` → bucket key present); `it('replaces a list instead of merging it by index')` (defaults `content.types` = 4 classes, published 1 class → exactly that 1); `PublishedConfigTest`: `it('applies a published hata config over the defaults')` (a temp published file setting `hata.content.trash_days` = 7 → `config('hata.content.trash_days') === 7` and an untouched key keeps its default).
- [ ] **Step 2: Run, expect FAIL.**
- [ ] **Step 3: Implement**; delete `config/admin.php`, `content.php`, `media.php`, `languages.php`, `permissions.php`.
- [ ] **Step 4: Run** — `composer test` (the settings, maintenance `config:cache` and media tests are the ones that will catch a missed key); green. Also `grep -rnE "config\('(admin|content|media|languages|permissions)\." src resources routes tests` → nothing.
- [ ] **Step 5: Commit** — `Read Hata's configuration from one hata config key`

---

### Task 7: The host's User model with Hata's trait

**Files:** Create `src/Contracts/AdminUser.php`, `src/Models/Concerns/IsAdminUser.php`; extend `src/Hata.php`; replace every `App\Models\User` in `src/`, `resources/`, `routes/`, `config/`, `database/` (70 files) with `Hata::userModel()` (queries, `instanceof` checks → `AdminUser`, type hints → `AdminUser&Authenticatable` or `Model&AdminUser` as PHPStan needs); policies and `Gate` type hints; `database/factories` that relate to users use `Hata::userModel()::factory()` only through the workbench factory; `workbench/app/Models/User.php` becomes the minimal host shape; migrations: Hata's user columns as alter migrations (Task 8 finalizes the list). Test: `tests/Feature/Package/HostUserModelTest.php`.

**Interfaces:**
- Produces: `interface AdminUser extends Illuminate\Contracts\Translation\HasLocalePreference, Laravel\Fortify\Contracts\PasskeyUser` with the methods Hata calls on users today (e.g. `isSuperAdmin(): bool`; list them from the current `User` model's public methods).
- Produces: `trait IsAdminUser` — uses `HasRoles`, `HasSearchIndex`, `PasskeyAuthenticatable`, `TwoFactorAuthenticatable`; contributes today's casts/fillable/hidden through `initializeIsAdminUser()` (merge, never replace the host's), `preferredLocale()`, and the other methods of today's `User`.
- Produces: `Hata::userModel(): string` — `config('auth.providers.users.model')`, throws a clear `LogicException` when that class does not implement `AdminUser`.
- The host User shape (what the installer writes, Task 11):

```php
class User extends Authenticatable implements AdminUser
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, IsAdminUser, Notifiable;
}
```

- [ ] **Step 1: Tests** — `it('uses the model configured as the auth user')` (point `auth.providers.users.model` at a second workbench model implementing `AdminUser` → the users list page lists its rows); `it('explains a user model without the hata trait')` (a plain `Authenticatable` model configured → `Hata::userModel()` throws with a message naming `IsAdminUser`); `it('keeps the host\'s own fillable and casts')` (workbench User declares an extra fillable `nickname` → `User::create([... 'nickname' => 'x'])` stores it and Hata's `locale` is fillable too).
- [ ] **Step 2: Run, expect FAIL.**
- [ ] **Step 3: Implement.**
- [ ] **Step 4: Run** — `composer test`; green; `grep -rn "App\\\\Models\\\\User" src resources routes config database` → nothing.
- [ ] **Step 5:** Update `hata-demo/app/Models/User.php` to the shape above; the user logs in on the demo (browser) and opens profile, security (2FA, passkeys page), users list.
- [ ] **Step 6: Commit** (both repos) — `Let the host own the User model; Hata adds a trait`

---

### Task 8: Migrations, morph map, and no reliance on the host's defaults

**Files:** `database/migrations/**`; `tests/Feature/Migrations/*` (delete with their migrations); `src/HataServiceProvider.php` (morph map); `workbench/app/Providers/WorkbenchServiceProvider.php` (remove the defaults); any code that relied on them. Tests: `tests/Feature/Package/MorphMapTest.php`, `tests/Feature/Package/HostDefaultsTest.php`.

- [ ] **Step 1: Migrations.** Remove Laravel's own (`create_users_table`, `create_cache_table`, `create_jobs_table`) from the package; Testbench loads Laravel's defaults (`defineDatabaseMigrations()` with `loadLaravelMigrations()` or the Testbench equivalent). Keep Hata's tables; fold the `add_*` column migrations of Hata's own tables into their `create_*` (allowed now, Global Constraints); Hata's columns on `users` (two-factor, locale, search_index) become one `add_hata_columns_to_users_table` alter migration that skips columns already present (the 2FA columns may come from the host). Delete `import_branding_logos_into_media`, `rewrite_local_media_storage_setting` and their tests (`ImportBrandingLogosTest`, `RewriteLocalMediaStorageSettingTest`). Timestamps keep Hata's tables after Laravel's.
- [ ] **Step 2: Morph map tests** — `it('stores short names in media usages')` (attach a media to an Entry and to a logo Setting → `usable_type` is `entry` / `setting`); `it('does not force the host to map its own models')` (a workbench model without a map entry can still be used in a morph relation, i.e. `Relation::requireMorphMap()` is false). Find every `getMorphClass()`/`morphs()` user (`MediaUsage`, `EntryGroup`, `DeleteEntry`, `Setting`, `Entry`) and map each Hata model: `entry`, `entry_group`, `setting`, plus any other found.
- [ ] **Step 3: Host defaults test** — remove the defaults from `WorkbenchServiceProvider`; `HostDefaultsTest`: `it('does not change the host date class')` (`now()` is an `Illuminate\Support\Carbon` after boot) and `it('does not set password defaults')` (`Password::default()` equals Laravel's untouched default). Run the whole suite; fix every failure by making Hata's code explicit (`CarbonImmutable` / `->toImmutable()` where a mutated date was the bug, explicit rules where a password rule relied on `Password::defaults()`), never by restoring the defaults. List the changed spots in the report.
- [ ] **Step 4: Run** — `composer test` and the MySQL run (Task 1 Step 7); green.
- [ ] **Step 5:** Demo: the user recreates the demo database (`!` `php artisan migrate:fresh && …` from Task 3 Step 4) and repeats the smoke check.
- [ ] **Step 6: Commit** — `Squash Hata's migrations, map its morph types and stop relying on host defaults`

---

### Task 9: Admin menu registry

**Files:** Create `src/Support/Admin/AdminMenu.php`, `src/Support/Admin/AdminMenuItem.php`; modify `resources/views/components/layouts/app/sidebar.blade.php` (render the registry; today only content types are dynamic), `HataServiceProvider` (register Hata's own sections), `ContentServiceProvider` (content types and their taxonomies as items). Test: `tests/Feature/Admin/AdminMenuTest.php`.

**Interfaces:**
- Produces: `final readonly class AdminMenuItem(string $key, string $label, string $icon, string $route, string|Closure|null $permission, string $group, int $order = 100, ?string $activePattern = null, ?string $parent = null)`. `$label` is a translation key or a plain string (rendered with `__()`); `$permission` is a gate ability (checked with `can`) or `Closure(AdminUser): bool`; `$activePattern` is a `routeIs()` pattern (default `$route`); `$parent` nests an item (taxonomies under their type).
- Produces: `AdminMenu` singleton: `group(string $key, string $label, int $order): static`, `add(AdminMenuItem $item): static`, `remove(string $key): static`, `for(AdminUser $user): array<string, array{label: string, items: list<AdminMenuItem>}>` (groups in order, only visible items, empty groups dropped). Registration happens in providers' `boot()`; the registry resolves routes lazily at render time.
- Hata's own items keep today's order, labels, icons and permissions exactly (dashboard, content types with taxonomies, media, users, roles, settings, maintenance).

- [ ] **Step 1: Tests** — `it('shows hata\'s sections to a super admin in today\'s order')` (render `/admin` → item labels in the same order as the current sidebar); `it('hides items the user may not open')` (editor without `users.view` → no Users link); `it('adds a module item and group')` (`AdminMenu::group('shop', 'Shop', 50)->add(new AdminMenuItem('shop.orders', 'Orders', 'shopping-cart', 'admin.dashboard', 'admin.access', 'shop'))` → link rendered under "Shop"); `it('drops an empty group')`; `it('nests taxonomies under their type')`.
- [ ] **Step 2: Run, expect FAIL.**
- [ ] **Step 3: Implement.**
- [ ] **Step 4: Run** — `composer test`; green.
- [ ] **Step 5: Browser** — the user compares the demo sidebar (desktop, phone width, collapsed) with before: identical.
- [ ] **Step 6: Commit** — `Build the admin sidebar from a menu registry`

---

### Task 10: The content-type generator writes into the site

**Files:** `src/Support/Content/ContentTypeGenerator.php`, `src/Console/Commands/MakeContentType.php`, their tests.

**Interfaces:**
- Defaults change to the host: class `app_path('ContentTypes/{Name}Type.php')`, namespace `App\ContentTypes`, generated `translationPrefix()` not overridden (`''`); config entry appended to the host's `config_path('hata.php')` → `content.types` (if the file is not published: publish it first with `hata-config`, then edit; print what was done); strings to the host's `lang_path('{locale}/content.php')` and `lang_path('{locale}/permissions.php')` for every locale in `hata.admin.interface_locales`, creating the files when missing; the content type's permissions register with prefix `'permissions'` (the host's file).
- The generator keeps its rule: a file whose shape it cannot follow is left untouched and the lines are printed.

- [ ] **Step 1: Tests** (on temp copies, as today) — `it('writes the class into the site\'s app/ContentTypes')`; `it('adds the class to the site\'s published hata config')`; `it('publishes the hata config first when the site has none')`; `it('writes labels into the site\'s lang files, creating them')`; `it('labels a generated type from the site\'s translations at runtime')` (register the generated class → `label()` reads the host line).
- [ ] **Step 2: Run, expect FAIL.**
- [ ] **Step 3: Implement.**
- [ ] **Step 4: Run** — `composer test`; green.
- [ ] **Step 5:** On the demo: `php artisan make:content-type Recipes`, then the user opens it in the admin (list, create, public address) and confirms; then delete the generated files from the demo.
- [ ] **Step 6: Commit** — `Generate content types into the site`

---

### Task 11: `php artisan hata:install`

**Files:** Create `src/Console/Commands/InstallCommand.php`, `src/Support/Install/HostFiles.php`, `database/seeders/HataSeeder.php`, `stubs/` (User model shape, AppServiceProvider defaults block, htaccess snippet); `tests/fixtures/host/` (verbatim Laravel 13 skeleton copies of `app/Models/User.php`, `routes/web.php`, `app/Providers/AppServiceProvider.php`, `database/seeders/DatabaseSeeder.php`, `composer.json`); tests `tests/Feature/Install/HostFilesTest.php`, `tests/Feature/Install/InstallCommandTest.php`.

**Interfaces:**
- Produces: `HostFiles` methods, each taking a file path, editing it by the skeleton's Pint shape, idempotent, returning `HostFileResult` (`changed`, `alreadyDone`, `unrecognized` + the lines to add by hand):
  - `addUserTrait(string $userModel): HostFileResult` (adds `implements AdminUser`, `use IsAdminUser`, imports)
  - `removeWelcomeRoute(string $webRoutes): HostFileResult` (removes the `Route::get('/', …welcome…)` statement; deleting `resources/views/welcome.blade.php` is done by the command)
  - `addHostDefaults(string $appServiceProvider): HostFileResult` (the starter kit's `configureDefaults()` block)
  - `addSeederCall(string $databaseSeeder): HostFileResult` (`$this->call(HataSeeder::class);`)
  - `ensureAssetsPublishScript(string $composerJson): HostFileResult` (`@php artisan vendor:publish --tag=laravel-assets --ansi --force` in `post-update-cmd`, JSON edited structurally, formatting kept at 4 spaces)
- Produces: `HataSeeder` = `permissions:sync` + `RoleSeeder` + super admin from `hata.admin.initial_admin` (today's `DatabaseSeeder` logic, decision 13); idempotent.
- Produces: `hata:install {--name=} {--email=} {--password=} {--no-migrate}`: runs the `HostFiles` steps, publishes `hata-config` (unless present) and `laravel-assets`, runs `migrate` (unless `--no-migrate`), `HataSeeder` (asks for the admin when the options and `ADMIN_*` env are missing and the console is interactive), `storage:link` when missing, then prints a report: each step `done` / `already done` / `do by hand:` + lines, and the web-server caching note (README section). English output.

- [ ] **Step 1: Tests** — `HostFilesTest` per method on temp copies of the fixtures: changed on the skeleton file; `alreadyDone` on the second run (file byte-identical); `unrecognized` and file untouched on a hand-edited variant (e.g. User already extends another class). `InstallCommandTest`: `it('installs on a fresh skeleton')` (temp host dir with the fixtures, paths injected; asserts each file changed, config published, admin user exists with `super-admin`); `it('is safe to run twice')`; `it('reports what it could not do')` (unrecognized User → exit code 0, output contains "do by hand" and the lines).
- [ ] **Step 2: Run, expect FAIL.**
- [ ] **Step 3: Implement.** Keep `HostFiles` free of `base_path()` (paths are parameters; the command passes the host's).
- [ ] **Step 4: Run** — `composer test`; green.
- [ ] **Step 5: End-to-end on a second fresh Laravel** in the scratchpad: `composer create-project laravel/laravel <scratchpad>/fresh`, path repository to `../hata` (absolute path), `composer require sliusardev/hata:*@dev`, `php artisan hata:install --name=Admin --email=admin@example.test --password=…` (SQLite inside the scratchpad, not the user's DB; if the classifier refuses, ask the user to run it with `!`). Expected: the install report has no "do by hand" lines, `php artisan route:list --name=admin.dashboard` lists the route, and `git diff` of the fresh app shows exactly the five host-file edits. Report the transcript.
- [ ] **Step 6: Commit** — `Add hata:install`

---

### Task 12: Documentation, CI, guidelines

**Files:** `README.md` (rewritten as the package guide: requirements; installing from the private repository — `repositories: vcs` + read-only fine-grained token in `auth.json`/`COMPOSER_AUTH`; `hata:install`; updating; web-server caching for `/storage/media` and `/vendor/hata`; extending: content types, theme override path `resources/views/vendor/hata/site/…` with the "an overridden view stops receiving fixes" warning, admin menu, admin assets, permissions with their translation prefix, admin routes (middleware `web, auth, verified, can:admin.access`, prefix `admin`, name `admin.`), Blade components and Livewire names from Task 4, seeders, migrations; what a site accepts by installing Hata (spec section); developing Hata with `hata-demo`), `CLAUDE.md` (structure section rewritten for the package; stack table; decision **71** "Hata is a Composer package" summarizing the spec with today's date; the migrations rule; Boost block handling now lives in the demo), `docs/specs/*.md` (paths, names, config keys, the extension points; new `docs/specs/package.md` linked from CLAUDE.md: install, update, extension API), `.github/workflows/tests.yml` (package: `composer install`, no `npm` build in CI since `dist/` is committed — add a job step that fails if `npm run build` changes `dist/`, on PHP 8.5 only; matrix unchanged; triggers push to `main` and pull requests), `resources/boost/guidelines/core.blade.php` (short: Hata's extension points and names, for agents on client sites), `hata-demo/CLAUDE.md`.

- [ ] **Step 1:** Write the docs.
- [ ] **Step 2:** `composer test` green; `git grep -nE "app/|App\\\\(Models|Support|Actions)" -- CLAUDE.md docs/specs README.md` → only the host's `app/` where meant.
- [ ] **Step 3:** Update the memory file (project progress) with the new layout and the demo path.
- [ ] **Step 4: Commit** — `Document Hata as a package`

---

## After the last task

Whole-branch review (fresh reviewer, most capable model), one fix wave, the user's full browser pass on the demo (the checklist from memory: list, form, editor, media library, site, phone width, dark mode, a color scheme), then merge `package` into `main` and push **only on the user's word**. After the merge the local `/Users/vitalii/Herd/hata/.env`, `database/database.sqlite` and `storage/app/**` of the old app are no longer used; ask the user before deleting them.

---

### Task 13: Configurable admin path (the user's request, 2026-11-23; run right after Task 8)

The admin panel answers at `/admin` on every site, which bots probe. A site picks its own path.

**Files:** `config/hata.php` (`admin.path` => `env('HATA_ADMIN_PATH', 'admin')`), `routes/admin.php` (prefix from the config), `src/HataServiceProvider.php` (validate the value at boot), any hard-coded `/admin` URL in `src/`, `resources/views/`, `resources/js/` (must become `route()`/config-built), `docs/specs/*` and CLAUDE.md "Admin routes" convention, README note (change it in `.env`, then rebuild the route cache: `php artisan optimize`). Test: `tests/Feature/Package/AdminPathTest.php`.

**Interfaces:**
- Produces: `config('hata.admin.path')` (string, no slashes). Only config/env, never a site setting: routes are cacheable and a wrong value from the UI could lock the admin out.
- Route names stay `admin.*`; only the URI prefix changes. `ReservedSlugs` already derives reserved first segments from registered routes, so the new path is reserved for content automatically and `admin` becomes a free slug — verify, don't duplicate.
- Validation at boot (clear `InvalidArgumentException` naming `HATA_ADMIN_PATH`): matches `^[a-z0-9]+(-[a-z0-9]+)*$` (one segment; nested paths not supported), is not a content language code from the catalogue (it would collide with `/<code>/…`), and is not a first segment of another non-content route (`login`, `register`, `settings`, `livewire`, `media`, `storage`, `vendor`, `dashboard`, `up`, …). Validation reads only config and the catalogue/route list, no queries.

- [ ] **Step 1: Tests** — `it('serves the admin under a configured path')` (path `cp-7f3k` set before routes load → GET `/cp-7f3k` as super admin 200, `/admin` 404, `route('admin.dashboard')` ends with `/cp-7f3k`); `it('frees the default admin slug for content')` (with the custom path, a published page with slug `admin` answers at `/admin`); `it('reserves the configured path for content')` (`ReservedSlugs::contains('cp-7f3k')`); `it('refuses an invalid admin path', ...)` dataset: `''`, `'a/b'`, `'Admin'`, `'en'` (a catalogue language code), `'login'`, `'settings'`.
- [ ] **Step 2: Run, expect FAIL.**
- [ ] **Step 3: Implement**; grep for literal `/admin` / `'admin/` in src, views, js and replace with route-built URLs.
- [ ] **Step 4: Run** — `composer test`; green.
- [ ] **Step 5: Browser** — the user sets `HATA_ADMIN_PATH` in the demo's `.env`, runs `php artisan optimize:clear`, opens the admin at the new path (sidebar links, media library, login redirect to the admin), then removes the variable.
- [ ] **Step 6: Commit** — `Let a site choose the admin path`

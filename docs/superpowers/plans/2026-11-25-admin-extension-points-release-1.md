# Admin extension points, release 1 (v0.4.0) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** One shared registry base for every admin extension point, the `Admin` entry point and the site's
`HataAdminServiceProvider`, a desktop top bar, collapsible menu groups and dashboard widgets (Hata ships two).

**Architecture:** An abstract `Registry` holds keys, order, permission, deferred `modify`/`remove` and the
registration-mistake handling; `AdminMenu` and `SettingsSections` move onto it without changing their public API, and
the new `AdminTopbar` and `DashboardWidgets` are small subclasses. Hata registers its own entries in a `booting`
callback so they exist before any other provider's `boot()`. The dashboard renders registered Livewire widgets
lazily in one bundled request.

**Tech Stack:** PHP 8.4, Laravel 13, Livewire 4.4 (lazy bundling), Flux 2.21 free (`flux:sidebar.group expandable`,
`flux:header`), Pest 5 on Testbench, PHPStan level 7.

**Spec:** `docs/superpowers/specs/2026-11-25-admin-extension-points-design.md` (sections 1–5; sections 6–10 are
release 2, not this plan).

## Global Constraints

- Flux **free** only; no new Composer or npm dependency.
- Every new label in `resources/lang/en` **and** `resources/lang/uk`, under `hata::`; `TranslationParityTest` must pass.
- Keys match `^[a-z0-9]+([.-][a-z0-9]+)*$`.
- Hata's own orders are steps of 10.
- Registries make **no queries** and resolve nothing (routes, components, translations) at registration.
- Registration mistake: `LogicException` naming registry and key in `local`/`testing`; elsewhere skip and
  `Log::warning` once per message per process.
- `modify`/`remove` of a key that is not there: skipped, never a mistake; reported by `hata:admin`.
- Existing calls `app(AdminMenu::class)->add(new AdminMenuItem(...))`, `->group(...)`, `->lazy(...)`, `->remove(...)`,
  `app(SettingsSections::class)->add/remove/for/visibleTo/open/pick/find`, `SettingsSections::ALIASES` keep working.
- Base Livewire classes live in `src/Livewire/Admin` (PHPStan does not analyse SFC files, decision 32).
- Shipped file names are plain ASCII (decision 74); new Livewire views made with `--emoji=false` or by hand.
- Any change to `resources/css` or `resources/js` rebuilds and commits `dist/` (`npm run build`).
- `composer test` (Pint, PHPStan, Pest) green at the end of every task.
- UI tasks (top bar, collapsible groups, dashboard) are shown to the user in the browser before the release
  (memory "UI first, then tests"): the demo `/Users/vitalii/Herd/hata-demo` runs the package from Packagist, so
  switch it to a path repository for the check and run `php artisan vendor:publish --tag=hata-assets --force` there
  after a `dist/` rebuild.

## Review Focus

1. **Provider order:** a site's provider that `remove`s or `modify`s a Hata entry, or `add`s one with the same key,
   must win however the providers are ordered. The test registers through a provider booted before Hata's
   registrations would have run (Task 1, `registrations survive provider order`).
2. **A lazy menu item hidden by a site:** `remove('content.posts')` must hide the posts item although it is added
   at read time (Task 3, `remove hides a lazy item`).
3. **A switched-off content type with a site `modify` on its menu item:** no exception in `testing`, the item is
   simply absent (Task 3, `modify of an absent lazy item is not a mistake`).
4. **A broken widget** (throws while rendering) must not blank the dashboard in production; it must surface in
   `testing` (Task 6, two tests).
5. **A user who may view no content type:** both Hata widgets are hidden, and with no widget at all the dashboard
   shows the empty state, not an empty grid (Task 7 and Task 6).

---

## File Structure

| File | Responsibility |
|---|---|
| `src/Support/Admin/Registry/Entry.php` | Interface: `key`, `order`, `permission` properties. |
| `src/Support/Admin/Registry/HasChanges.php` | Trait: `with(mixed ...$changes): static` for readonly entries. |
| `src/Support/Admin/Registry/Registry.php` | Abstract base (add, remove, modify, has, find, all, entriesFor, allows, unmatched, problem). |
| `src/Support/Admin/AdminMenu.php`, `AdminMenuItem.php` | Moved onto the base; groups gain `collapsible`; `modifyGroup`. |
| `src/Support/Admin/SettingsSections.php`, `SettingsSection.php` | Moved onto the base. |
| `src/Support/Admin/AdminTopbar.php`, `TopbarItem.php` | Top bar registry and its entry. |
| `src/Support/Admin/DashboardWidgets.php`, `DashboardWidget.php` | Widget registry and its entry. |
| `src/Admin.php` | `Hata\Admin` static entry point. |
| `src/Livewire/Admin/Widget.php` | Base Livewire class of a widget (card, placeholder, error card). |
| `src/Livewire/Admin/Widgets/ContentWidget.php`, `RecentChangesWidget.php` + views `resources/views/livewire/admin/widgets/*.blade.php` | Hata's two widgets. |
| `resources/views/dashboard.blade.php` | Grid of the widgets the user may see, or the empty state. |
| `resources/views/layouts/app/sidebar.blade.php` | Desktop top bar, collapsible groups, "View site" mobile-only. |
| `resources/views/partials/admin/topbar.blade.php` | Renders the top bar items. |
| `src/Console/Commands/AdminRegistriesCommand.php` (`hata:admin`) | Prints every registry. |
| `src/Console/Commands/MakeDashboardWidget.php` + `stubs/dashboard-widget.stub`, `stubs/dashboard-widget-view.stub` | Generator. |
| `stubs/hata-admin-service-provider.stub`, `src/Support/Install/HostFiles.php`, `InstallCommand.php`, `HostPaths.php` | The site's provider and its installer step. |
| `docs/specs/admin-extensions.md` (new), `admin-menu.md`, `package.md`, `CLAUDE.md` | Docs and decision 81. |

---

### Task 1: The registry base

**Files:**
- Create: `src/Support/Admin/Registry/Entry.php`, `HasChanges.php`, `Registry.php`
- Create: `tests/fixtures/Registry/FakeEntry.php`, `tests/fixtures/Registry/FakeRegistry.php` (namespace `Tests\Fixtures\Registry`)
- Test: `tests/Feature/Admin/Registry/RegistryTest.php`

**Interfaces:**
- Produces:
  ```php
  interface Entry {
      public string $key { get; }
      public int $order { get; }
      /** string ability | Closure(AdminUser): bool | null */
      public string|Closure|null $permission { get; }
  }
  trait HasChanges { public function with(mixed ...$changes): static; } // new static(...[...get_object_vars($this), ...$changes])

  /** @template TEntry of Entry */
  abstract class Registry {
      public function add(Entry $entry): static;            // same key replaces, keeps first insertion position; clears an earlier remove() of that key
      public function remove(string $key): static;          // recorded; hides the key at read (incl. entries added at read time)
      public function modify(string $key, Closure $change): static; // Closure(TEntry): TEntry, queued, applied at read in call order
      public function has(string $key): bool;               // after removes, before permission
      public function find(string $key): ?Entry;            // with modifies applied
      /** @return list<TEntry> */ public function all(): array;   // sorted by order, ties by insertion; removes + modifies applied; invalid entries reported via problem() and dropped
      /** @return list<TEntry> */ public function entriesFor(AdminUser $user): array; // all() filtered by allows()
      public function allows(AdminUser $user, Entry $entry): bool;
      /** @return list<string> e.g. "modify [x]", "remove [y]" */ public function unmatched(): array; // computed by the last all()
      abstract public function name(): string;              // "admin menu", "settings section", … used in messages
      /** @return iterable<TEntry> */ protected function extraEntries(): iterable; // default []: entries worked out at read (AdminMenu's lazy providers)
      protected function validate(Entry $entry): ?string;   // default null; a string = the problem, entry dropped
      protected function problem(string $key, string $problem): void; // "The {name()} [{key}] is left out: {problem}."
  }
  ```
  A bad key is checked in `all()` (`validate` result `'its key is not lowercase letters and digits joined by hyphens or dots'`).

- [ ] **Step 1: Write the failing tests** in `RegistryTest.php`, using `FakeRegistry`/`FakeEntry` (`final readonly`, `use HasChanges`, props `key`, `order = 100`, `permission = null`, `label = ''`):
  - `entries come back by order, ties in the order added`: add b(20), a(10), c(20) → keys `['a','b','c']`.
  - `adding a key again replaces the entry and keeps its place`: add a(10,'x'), b(10), a(10,'y') → `['a','b']`, `find('a')->label === 'y'`.
  - `modify changes an entry when read, even if called before the add`: `modify('a', fn ($e) => $e->with(order: 5))` then add a(30), b(10) → `['a','b']`.
  - `remove hides a key whatever the order of calls, and a later add brings it back`: remove('a'), add a → shown; add a, remove('a') → hidden.
  - `modify or remove of a missing key is skipped and reported`: `modify('zzz', …)`, `remove('yyy')`; `all()` does not throw; `unmatched() === ['modify [zzz]', 'remove [yyy]']`.
  - `entriesFor filters by ability, closure and null`: three entries with `'users.view'`, `fn () => false`, `null`; a user `withPermissions(['users.view'])` sees the first and third.
  - `a bad key throws in testing`: add key `Bad_Key` → `all()` throws `LogicException` with message `The fake entry [Bad_Key] is left out: its key is not lowercase letters and digits joined by hyphens or dots.`
  - `a bad key is skipped and logged once in production`: `app()->detectEnvironment(fn () => 'production')`, `Log::spy()`, call `all()` twice → entry absent, `Log::warning` called once.
  - `registrations survive provider order` (Review Focus 1): a site-style registration (`remove('a')`, `modify('b', …)`) made **before** the "Hata" `add`s of a, b → a hidden, b modified.
  - `dotted keys are valid`: `content.posts` accepted.

- [ ] **Step 2: Run** `vendor/bin/pest tests/Feature/Admin/Registry/RegistryTest.php` — expected FAIL (classes missing).

- [ ] **Step 3: Implement** `Entry`, `HasChanges`, `Registry` as in Interfaces. The base keeps three things: `$entries` (key ⇒ entry, insertion-ordered), `$removed` (key ⇒ true), `$modifications` (list of `[key, Closure]`); `all()` merges `extraEntries()`, drops removed keys, applies modifications in call order (recording unmatched), validates, sorts. Warned messages are kept in a `static array $warned` per class (as today). The static `$warned` must be reset between tests if a test depends on it: add `public static function forgetWarnings(): void` (internal, used by tests only).

- [ ] **Step 4: Run** the test file — PASS. Run `composer types:check` — no errors.

- [ ] **Step 5: Commit** `git commit -m "Add a shared base for the admin registries"`

### Task 2: SettingsSections on the base

**Files:**
- Modify: `src/Support/Admin/SettingsSections.php`, `src/Support/Admin/SettingsSection.php`
- Test: `tests/Feature/Admin/Settings/SettingsSectionsTest.php` (add tests; all existing ones must stay green unchanged)

**Interfaces:**
- Consumes: `Registry`, `Entry`, `HasChanges` (Task 1).
- Produces: `SettingsSections extends Registry<SettingsSection>`; `SettingsSection implements Entry` and has `with()`. `for()`, `visibleTo()`, `open()`, `pick()`, `ALIASES` unchanged in signature and behaviour. `name()` returns `'settings section'`, so messages stay `The settings section [x] is left out: …`. Its key rule stays hyphens-only (override `validate()` to reject dots, since a section key is a `?tab=` value).

- [ ] **Step 1: Add failing tests:** `a section can be modified by key` (`modify('mail', fn ($s) => $s->with(order: 5))` → `for($admin)[0]->key === 'mail'`), `a removed section is not offered by open()` (`remove('mail')`, `open($admin, 'mail')->key === 'site'`).
- [ ] **Step 2: Run** `vendor/bin/pest tests/Feature/Admin/Settings` — new tests FAIL.
- [ ] **Step 3: Implement:** drop the class's own storage, sorting, `problem()` and `$warned` in favour of the base; `for()` = `entriesFor()`; component check moves into `validate()`; `visibleTo()` keeps checking permissions only (no component resolution) over the entries after removes.
- [ ] **Step 4: Run** `vendor/bin/pest tests/Feature/Admin/Settings` — all PASS; `composer types:check` clean.
- [ ] **Step 5: Commit** `git commit -m "Build the settings sections on the registry base"`

### Task 3: AdminMenu on the base, collapsible groups in the registry, Hata's entries in `booting`

**Files:**
- Modify: `src/Support/Admin/AdminMenu.php`, `src/Support/Admin/AdminMenuItem.php`, `src/HataServiceProvider.php` (`registerAdminMenu`, `registerSettingsSections` called from `$this->app->booting(...)` registered in `register()`)
- Test: `tests/Feature/Admin/AdminMenuTest.php` (add tests; existing stay green)

**Interfaces:**
- Consumes: Task 1.
- Produces:
  - `AdminMenu extends Registry<AdminMenuItem>`; `name()` = `'admin menu item'` (existing message text `The admin menu item [x] is left out: …` unchanged).
  - `group(string $key, string $label, int $order, bool $collapsible = false): static`
  - `modifyGroup(string $key, Closure $change): static` — `Closure(array{label: string, order: int, collapsible: bool}): array{…}`, applied at read; a missing group is skipped.
  - `for(AdminUser $user): array<string, array{label: string, collapsible: bool, items: list<AdminMenuItem>}>` (adds `collapsible`; existing keys unchanged).
  - `lazy()` providers feed `extraEntries()`; tree ordering, route check and parent checks stay in `AdminMenu` (route check in `validate()`, group/parent checks in `for()` as today).
  - Hata's groups: `content` and `administration` registered with `collapsible: true`.

- [ ] **Step 1: Add failing tests:**
  - `remove hides a lazy item` (Review Focus 2): register posts type, `app(AdminMenu::class)->remove('content.posts')`, render the admin as an editor → no link to the posts list.
  - `modify of an absent lazy item is not a mistake` (Review Focus 3): posts registered but switched off (`content_disabled_types`), `modify('content.posts', fn ($i) => $i->with(order: 1))` → dashboard renders 200 in `testing`.
  - `an item can be moved by modify`: `modify('media', fn ($i) => $i->with(order: 1))` → media is the first item of its group in `for()`.
  - `groups can be made collapsible and changed by modifyGroup`: `for($admin)['content']['collapsible'] === true`; after `modifyGroup('content', fn ($g) => [...$g, 'collapsible' => false])` it is false.
  - `a site provider booted before Hata's registration still wins`: covered by moving Hata's registration to `booting`; assert by calling `remove('media')` in a test-registered provider's `boot()` → media absent.
- [ ] **Step 2: Run** `vendor/bin/pest tests/Feature/Admin/AdminMenuTest.php` — new tests FAIL.
- [ ] **Step 3: Implement** as in Interfaces; `AdminMenuItem implements Entry`, `use HasChanges`. `ContentServiceProvider` keeps registering its lazy provider in `boot()` (order does not matter for lazy providers).
- [ ] **Step 4: Run** `vendor/bin/pest tests/Feature/Admin` and `composer types:check` — PASS.
- [ ] **Step 5: Commit** `git commit -m "Build the admin menu on the registry base and mark groups collapsible"`

### Task 4: The `Admin` entry point and the top bar

**Files:**
- Create: `src/Admin.php`, `src/Support/Admin/AdminTopbar.php`, `src/Support/Admin/TopbarItem.php`, `resources/views/partials/admin/topbar.blade.php`
- Modify: `src/HataServiceProvider.php` (singleton `AdminTopbar`, Hata's `view-site` item in the `booting` callback), `resources/views/layouts/app/sidebar.blade.php`
- Test: `tests/Feature/Admin/AdminTopbarTest.php`

**Interfaces:**
- Consumes: Task 1, Task 3.
- Produces:
  ```php
  final class Admin {
      public static function menu(): AdminMenu;  public static function topbar(): AdminTopbar;
      public static function dashboard(): DashboardWidgets; // Task 6 adds the class; add this method there
      public static function settings(): SettingsSections;  public static function assets(): AdminAssets;
  }
  final readonly class TopbarItem implements Entry {
      public static function link(string $key, string $label, string $icon, string $route = '', array $routeParameters = [], ?string $url = null, bool $newTab = false, string|Closure|null $permission = null, int $order = 100, string $side = 'end'): self;
      public static function component(string $key, string $component, string|Closure|null $permission = null, int $order = 100, string $side = 'end'): self;
      public function href(): ?string; // route (relative) or url; null for a component
  }
  final class AdminTopbar extends Registry { /** @return array{start: list<TopbarItem>, end: list<TopbarItem>} */ public function for(AdminUser $user): array; }
  ```
  `validate()`: a link with neither route nor url, or an undefined route → problem; `side` not `start|end` → problem. Hata's item: `TopbarItem::link('view-site', 'hata::navigation.view_site', 'arrow-top-right-on-square', route: 'home', newTab: true, order: 100)`.

- [ ] **Step 1: Write failing tests:**
  - `the desktop top bar shows the view site link in a new tab`: admin GET `admin.dashboard` → contains `data-test="admin-topbar"`, a link to `/` with `target="_blank"` inside it.
  - `the sidebar view site link is for mobile only`: the sidebar's view-site item carries `lg:hidden`.
  - `a site adds a link and a component to the top bar`: register `TopbarItem::link('docs', 'Docs', 'book-open', url: 'https://example.com', side: 'start')` and `TopbarItem::component('note', 'test-topbar-note')` (anonymous component registered in the test via `Blade::component` or a view in `workbench/resources/views/components`) → both render, `docs` before the end side.
  - `top bar items follow their permission`: item with `'users.view'` hidden for a user without it.
  - `an undefined route is a registration mistake`: link to `no.such.route` → rendering the dashboard throws in `testing`.
  - `the top bar makes no query`: `DB::enableQueryLog()` around `app(AdminTopbar::class)->for($admin)` → 0 queries.
  - `Admin returns the singletons`: `Admin::menu() === app(AdminMenu::class)` (and topbar, settings, assets).
- [ ] **Step 2: Run** `vendor/bin/pest tests/Feature/Admin/AdminTopbarTest.php` — FAIL.
- [ ] **Step 3: Implement.** Layout: a second `<flux:header class="max-lg:hidden h-16 border-b border-sidebar-border" data-test="admin-topbar">` holding `@include('hata::partials.admin.topbar')` (start items, `<flux:spacer />`, end items); links as `flux:button variant="ghost" size="sm" :icon` with `target="_blank" rel="noopener"` when `newTab`; components with `<x-dynamic-component :component="$item->component" />`. The sidebar's bottom "View site" nav gets `class="lg:hidden"`.
- [ ] **Step 4: Run** the test file and `vendor/bin/pest tests/Feature/Admin` — PASS.
- [ ] **Step 5: Commit** `git commit -m "Add the Admin entry point and the desktop top bar"`

### Task 5: Collapsible groups in the sidebar

**Files:**
- Modify: `resources/views/layouts/app/sidebar.blade.php`, `tests/Feature/Admin/AdminMenuTest.php` (`renderedSidebar()` reads the heading of both group shapes: plain `div[1]`, expandable `button span`)
- Test: `tests/Feature/Admin/AdminMenuTest.php`

**Interfaces:**
- Consumes: `AdminMenu::for()` with `collapsible` (Task 3).

- [ ] **Step 1: Add failing tests:**
  - `collapsible groups render as expandable and open on the current page`: admin on `admin.users.index` → the `administration` group is a `ui-disclosure` with `open` and `data-menu-group="administration"`; the `platform` group is a plain group.
  - `a collapsible group without the current page is rendered open and left to the browser`: on `admin.dashboard` the `content` group has `data-menu-group="content"` and no `data-menu-current`.
- [ ] **Step 2: Run** — FAIL.
- [ ] **Step 3: Implement:** `<flux:sidebar.group :expandable="$group['collapsible']" :expanded="true" data-menu-group="{{ $key }}" @if ($holdsCurrent) data-menu-current @endif>`; keep the existing heading style for non-collapsible groups. A small Alpine `x-init` on `flux:sidebar.nav` reads `localStorage['hata.menu.collapsed']` (JSON list of group keys) and removes `open`/`data-open` from those groups unless they carry `data-menu-current`; a click on a group's button toggles its key in that list. Wrap every `localStorage` access in try/catch. No server state, no request.
- [ ] **Step 4: Run** `vendor/bin/pest tests/Feature/Admin/AdminMenuTest.php` — PASS (old tests too).
- [ ] **Step 5: Commit** `git commit -m "Make the content and administration menu groups collapsible"`

### Task 6: Dashboard widgets: registry, base class, dashboard page

**Files:**
- Create: `src/Support/Admin/DashboardWidgets.php`, `src/Support/Admin/DashboardWidget.php`, `src/Livewire/Admin/Widget.php`, `resources/views/components/admin/widget-card.blade.php`
- Modify: `resources/views/dashboard.blade.php`, `src/Admin.php` (`dashboard()`), `src/HataServiceProvider.php` (singleton), `resources/lang/{en,uk}/navigation.php` or a new `dashboard.php` (`hata::dashboard.empty.heading`, `.empty.text`, `.widget_failed`)
- Create: `tests/fixtures/Widgets/{HelloWidget,BrokenWidget}.php` (+ views in `workbench/resources/views/widgets/`)
- Test: `tests/Feature/Admin/DashboardWidgetsTest.php`; keep `tests/Feature/DashboardTest.php` green

**Interfaces:**
- Consumes: Task 1.
- Produces:
  ```php
  final readonly class DashboardWidget implements Entry { use HasChanges;
      public function __construct(public string $key, public string $component, public string|Closure|null $permission = null, public string $width = 'third', public int $order = 100) {} }
  final class DashboardWidgets extends Registry { /** @return list<DashboardWidget> */ public function for(AdminUser $user): array; }
  // validate(): Livewire::exists($component) else problem; width not third|half|full → problem
  abstract class Widget extends \Livewire\Component {
      abstract public function title(): string;
      abstract protected function body(): \Illuminate\Contracts\View\View; // all data work happens here
      public function render(): View;       // the card frame around body(); on Throwable: rethrow in local/testing, else log and render the error card
      public function placeholder(): string; // the card frame with a skeleton, same height class as the card
  }
  ```
  Grid: `grid gap-4 lg:grid-cols-6`; `third` = `lg:col-span-2`, `half` = `lg:col-span-3`, `full` = `lg:col-span-6`; below `lg` full width (spec says below `md`; use `md:grid-cols-6` if the cards fit — builder's call within the admin look). Each widget: `<livewire:dynamic-component :is="$widget->component" :key="'widget-'.$widget->key" lazy.bundle />` (confirm the bundled-lazy syntax of Livewire 4.4 with `search-docs`/the vendor source before writing it).

- [ ] **Step 1: Write failing tests:**
  - `the dashboard renders the widgets the user may see, in order and width`: register `hello` (half, order 5) and a second with `'users.view'`; an admin without `users.view` sees only `hello`, wrapped in `lg:col-span-3`.
  - `with no widget visible the dashboard shows the empty state`: remove Hata's widgets → `__('hata::dashboard.empty.heading')` shown, no grid.
  - `a widget loads lazily`: the dashboard response contains the placeholder, not the widget body; `Livewire::test(HelloWidget::class)` renders the title and body.
  - `a broken widget surfaces in testing` (Review Focus 4): `Livewire::test(BrokenWidget::class)` throws.
  - `a broken widget shows an error card in production` (Review Focus 4): with environment `production`, `Log::spy()`, `Livewire::test(BrokenWidget::class)->assertSee(__('hata::dashboard.widget_failed'))`; warning logged.
  - `a widget whose component does not exist is a registration mistake`.
- [ ] **Step 2: Run** `vendor/bin/pest tests/Feature/Admin/DashboardWidgetsTest.php` — FAIL.
- [ ] **Step 3: Implement** as in Interfaces; the dashboard keeps `Route::view('/', 'hata::dashboard')` and reads `Admin::dashboard()->for(auth()->user())`. Translations in both languages.
- [ ] **Step 4: Run** the test file, `tests/Feature/DashboardTest.php`, `TranslationParityTest` — PASS.
- [ ] **Step 5: Commit** `git commit -m "Render the dashboard from registered lazy widgets"`

### Task 7: Hata's widgets: Content and Recent changes

**Files:**
- Create: `src/Livewire/Admin/Widgets/ContentWidget.php`, `RecentChangesWidget.php`, views `resources/views/livewire/admin/widgets/content.blade.php`, `recent-changes.blade.php`, Livewire names `hata::admin.widgets.content`, `hata::admin.widgets.recent-changes` (register the classes with `Livewire::component()` in `HataServiceProvider`)
- Modify: `src/HataServiceProvider.php` (register both in the `booting` callback), `resources/lang/{en,uk}/dashboard.php`
- Test: `tests/Feature/Admin/HataWidgetsTest.php`

**Interfaces:**
- Consumes: `Widget`, `DashboardWidget`, `ContentTypes::active()`, `EntryPolicy::viewAny($actor, $type)` via `Gate::forUser($user)->allows('viewAny', [Entry::class, $type])`.
- Produces: `DashboardWidget('content', 'hata::admin.widgets.content', $anyType, 'third', 10)` and `DashboardWidget('recent-changes', 'hata::admin.widgets.recent-changes', $anyType, 'half', 20)`, where `$anyType = fn (AdminUser $u): bool => <the user may viewAny at least one active type>`.
  - Content: one query `Entry::query()->whereIn('type', $visibleTypes)->groupBy('type', 'status')->selectRaw('type, status, count(*) as total')` (trashed excluded by the soft-delete scope); a row per visible type in `ContentTypes::active()` order: plural label, published count, drafts count (taxonomies: total only), linking to `admin.content.index` for the type.
  - Recent changes: one query, `Entry::query()->whereIn('type', $visibleTypes)->latest('updated_at')->limit(8)->get(['id','type','locale','title','status','updated_at'])`; each row: title linking to `admin.content.edit`, the type's label, the language code when the site has more than one content language, a draft badge, `updated_at` in `hata.admin.timezone` with `diffForHumans()`. My call over the spec's "author eager-loaded": no author column, so the widget stays one query.

- [ ] **Step 1: Write failing tests:**
  - `the content widget counts published and draft records per visible type in one query`: pages 2 published + 1 draft, a trashed page; `Livewire::test('hata::admin.widgets.content')` as admin shows 2 and 1; query log for the render has exactly 1 query on `entries`.
  - `the content widget hides types the user may not view`: posts registered; user with `pages.view` only does not see posts.
  - `recent changes lists the 8 latest edited records with links`: 10 pages with increasing `updated_at` → 8 rows, newest first, each links to its edit route; 1 query on `entries`.
  - `a user who may view no type sees neither widget` (Review Focus 5): user with `admin.access` only → dashboard has neither widget and shows the empty state.
- [ ] **Step 2: Run** `vendor/bin/pest tests/Feature/Admin/HataWidgetsTest.php` — FAIL.
- [ ] **Step 3: Implement.** Cards follow the admin look (memory "Polished client-facing UI"): rows as a compact list with zinc separators, counts right-aligned, an empty line ("No records yet") per widget.
- [ ] **Step 4: Run** the file and `TranslationParityTest` — PASS.
- [ ] **Step 5: Commit** `git commit -m "Ship the content and recent changes dashboard widgets"`

### Task 8: `hata:admin`

**Files:**
- Create: `src/Console/Commands/AdminRegistriesCommand.php` (signature `hata:admin`), register in `HataServiceProvider`
- Modify: each registry gets `describe(Entry $entry): string` (abstract in `Registry`): menu → `route [admin.media.index] in group [content]`, settings → component, topbar → route/url or component, dashboard → component + width
- Test: `tests/Feature/Console/AdminRegistriesCommandTest.php`

**Interfaces:**
- Consumes: `Registry::all()`, `unmatched()`, `describe()`, `Admin::*()`.
- Output: for each registry (`Admin menu`, `Top bar`, `Dashboard widgets`, `Settings sections`) a `table()` with columns `Key | Order | Permission | What` (permission: the ability, `closure` or `everyone`); after it, a line `Unmatched: modify [x], remove [y]` when any. Entries that fail validation are printed under `Left out:` with the problem instead of throwing (call `all()` inside a catch of `LogicException` per entry: add `Registry::inspect(): array{entries: list<TEntry>, problems: array<string, string>, unmatched: list<string>}` that never throws, used only by this command).

- [ ] **Step 1: Write failing tests:** `it lists Hata's own entries` (`artisan('hata:admin')` output contains `view-site`, `content`, `recent-changes`, `site`, `dashboard`), `it reports unmatched modify and remove` (`Admin::dashboard()->remove('nope')` → `Unmatched: remove [nope]`), `it reports a broken entry instead of failing` (widget with missing component → exit code 0, `Left out:` line).
- [ ] **Step 2: Run** — FAIL. **Step 3: Implement.** **Step 4: Run** — PASS.
- [ ] **Step 5: Commit** `git commit -m "Add hata:admin to list what the admin registries hold"`

### Task 9: `make:dashboard-widget`

**Files:**
- Create: `src/Console/Commands/MakeDashboardWidget.php`, `stubs/dashboard-widget.stub`, `stubs/dashboard-widget-view.stub`
- Test: `tests/Feature/Console/MakeDashboardWidgetTest.php`

**Interfaces:**
- `php artisan make:dashboard-widget Orders [--width=third|half|full]` writes `app/Livewire/Widgets/OrdersWidget.php` (`App\Livewire\Widgets\OrdersWidget extends Hata\Livewire\Admin\Widget`, `title()` returning `__('Orders')`, `body()` returning `view('livewire.widgets.orders')`) and `resources/views/livewire/widgets/orders.blade.php`; refuses to overwrite an existing file (exit 1, message); prints the line to paste into `HataAdminServiceProvider::boot()`:
  `Admin::dashboard()->add(new DashboardWidget('orders', \App\Livewire\Widgets\OrdersWidget::class, width: 'third'));` and that it does not edit the provider. Paths through `app_path()`/`resource_path()` (it writes into the site). Mirror `MakeContentType`'s structure and output style.

- [ ] **Step 1: Write failing tests** (in a temp app path as `MakeContentTypeTest` does): files created with the class name and view name above; second run refuses; output contains the `Admin::dashboard()->add(` line; the generated class passes `php -l`.
- [ ] **Step 2–4:** run (FAIL), implement, run (PASS).
- [ ] **Step 5: Commit** `git commit -m "Add make:dashboard-widget"`

### Task 10: The site's `HataAdminServiceProvider` and the installer step

**Files:**
- Create: `stubs/hata-admin-service-provider.stub`
- Modify: `src/Support/Install/HostFiles.php` (`addAdminProvider(string $providerPath, string $bootstrapProviders): HostFileResult`), `src/Support/Install/HostPaths.php` (`adminProvider` = `app/Providers/HataAdminServiceProvider.php`, `bootstrapProviders` = `bootstrap/providers.php`), `src/Console/Commands/InstallCommand.php` (step `Admin extensions provider (…)` after `Host defaults`), `tests/fixtures/host/bootstrap/providers.php` (Laravel 13 skeleton content)
- Test: `tests/Feature/Install/HostFilesTest.php`, `tests/Feature/Install/InstallCommandTest.php`

**Interfaces:**
- Stub: namespace `App\Providers`, `class HataAdminServiceProvider extends ServiceProvider`, `boot()` with one commented example per point in the spec's order: menu (`Admin::menu()->add(new AdminMenuItem(...))`, `->modify('media', fn ($item) => $item->with(order: 5))`, `->modifyGroup(...)`), top bar (link and component), dashboard (add, remove `recent-changes`), settings section (pointer to `docs/specs/package.md`), admin assets; plus a header comment linking `docs/specs/admin-extensions.md` and naming `php artisan hata:admin`. Release-2 points are **not** in the stub yet.
- `addAdminProvider`: writes the file when missing (never overwrites: an existing file is `AlreadyDone`), and adds `App\Providers\HataAdminServiceProvider::class` to `bootstrap/providers.php` with `ServiceProvider::addProviderToBootstrapFile()` semantics (already listed → unchanged; file not in the skeleton shape → `Unrecognized` with the by-hand line).

- [ ] **Step 1: Write failing tests:** HostFiles: `it writes the admin provider and lists it` (fixture copy → file exists, providers.php contains the class once), `it leaves an existing provider untouched`, `it prints a by-hand line for an unrecognized providers file`; InstallCommand: the report has the `Admin extensions provider` step `done` on a fresh host, `already done` on a second run.
- [ ] **Step 2–4:** run (FAIL), implement, run `vendor/bin/pest --testsuite=Install` and `tests/Feature/Install` (PASS).
- [ ] **Step 5: Commit** `git commit -m "Write HataAdminServiceProvider into the site on install"`

### Task 11: Docs, decision, release notes

**Files:**
- Create: `docs/specs/admin-extensions.md`: the base (verbs, keys, order, permission, mistakes, provider order and `booting`), `Admin::`, `HataAdminServiceProvider`, `hata:admin`, and per registry a table of Hata's own keys and orders (menu groups and items, top bar `view-site` 100, widgets `content` 10 / `recent-changes` 20, settings sections 10–70), the widget base class with a full example, `make:dashboard-widget`; a "Release 2 (planned)" line pointing at the design.
- Modify: `docs/specs/admin-menu.md` (`modify`, `modifyGroup`, `collapsible`, remove of lazy items), `docs/specs/package.md` (Extension API list uses `Admin::`; "What changed for v0.4.0": the dashboard is widgets, "View site" moved to the top bar, existing sites run `php artisan hata:install` again or copy the stub to get `HataAdminServiceProvider`), `docs/specs/site-settings.md` (sections are on the shared base; `modify`), `CLAUDE.md` (Specs list gets `admin-extensions.md`; Package structure line on the registries; decision **81** "Admin extension points share one registry base" with date 2026-11-25, the reasons from the spec's Intent and Approaches, and the calls: Hata registers in `booting`, missing keys are skipped and reported, dotted keys allowed, top bar desktop-only, recent changes without author).

- [ ] **Step 1: Write the docs** as listed.
- [ ] **Step 2: Run** `composer test` — Pint, PHPStan, Pest all green (SQLite); then the MySQL run as CI does (never at the same time as SQLite).
- [ ] **Step 3: Commit** `git commit -m "Document the admin extension points"`
- [ ] **Step 4: Browser check with the user** (Global Constraints): top bar, collapsing groups (state kept across navigation and reload), dashboard with both widgets, a widget registered from the demo's `HataAdminServiceProvider`. Fix what they find, then merge on their word, push, tag `v0.4.0` (memory "Tag releases myself"), and put the demo back on Packagist `^0.4`.

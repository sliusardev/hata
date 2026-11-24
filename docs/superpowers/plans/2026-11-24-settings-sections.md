# Settings Sections Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Split the Site settings page into a shell plus one Livewire component per section, merge the tabs to
seven (Site, Languages, Content types, Access, Email, Appearance, Media), and let a site or module add its own
sections and setting keys through registries, with per-section access.

**Architecture:** Two registries filled at boot (`SettingsSections` for the page, setting definitions in the
`SiteSettings` singleton for storage/config), an abstract Livewire base class `SettingsSectionPage` that every
section extends (Hata's own and a site's), and a thin shell page that renders only the open section through
`<livewire:dynamic-component>`. Hata's sections are SFCs over the base class (the `EntryFormPage` pattern); their
logic stays in classes where PHPStan sees it.

**Tech Stack:** PHP 8.4, Laravel 13, Livewire 4 (SFC), Flux free 2.21, Pest 5 on Testbench, PHPStan level 7.

**Spec:** `docs/superpowers/specs/2026-11-24-settings-sections-design.md` (read it first; also
`docs/specs/site-settings.md`, the behaviour that must survive, and `docs/specs/admin-menu.md`, the registry style
to copy).

## Global Constraints

- Branch `settings-sections` from `main`; first commit adds both design docs of 2026-11-24 (this one and
  `2026-11-24-seo-sitemap-home-design.md`) and this plan.
- Every rule in `docs/specs/site-settings.md` keeps working, except the two the spec changes on purpose: logos are
  saved by Appearance's Save, and Save stores only changed fields.
- Existing tests are moved and retargeted, never dropped (CLAUDE.md: no test deletion without approval). Each task
  states which test file's cases it moves.
- No new dependency. Flux free only (no Pro components).
- Every new translation key goes into `resources/lang/en` and `resources/lang/uk` in the same commit
  (`TranslationParityTest`).
- PHP files: `vendor/bin/pint --dirty --format agent` before each commit. New PHP logic in `src/` (PHPStan does not
  analyse SFCs, decision 32).
- If a task adds Tailwind classes not used before, run `npm run build` and commit `dist/` (CI checks freshness).
- Per-request cost: the shell and registries make no query; a section runs only its own queries.
- Section order and keys: `site` 10, `languages` 20, `content` 30, `access` 40, `mail` 50, `appearance` 60,
  `media` 70. Aliases: `general` → `site`, `logo` → `appearance`, `colors` → `appearance`.
- Release `v0.2.0` after merge (breaking: `SiteSettings` constants removed, `SiteSettingsForm` removed).
- Test runs: `vendor/bin/pest <path>`; full gate `composer test`. Never `--parallel`.

## Review Focus

1. A site defines a setting key in its provider's `boot()` (after Hata applied the settings): its stored value
   must still reach config on that same request — Task 1 test `a key defined after apply is applied at once`.
2. Saving a section where nothing changed writes no `settings` row and does not freeze `.env` values — Task 3 test
   `save without changes stores nothing`, Task 4 test `untouched site name keeps following config`.
3. A user who may open only a module's section calls a Hata section's action directly (a crafted Livewire request
   to a component they mounted earlier, or after the permission was revoked) — Task 3 test
   `every action re-checks the section permission`, Task 8 test `a hata section is 403 for a module-only user`.
4. An Appearance save with a valid new logo and an invalid color stores neither — Task 6 test
   `appearance saves logos and colors all or nothing`.
5. Old links (`?tab=general|logo|colors`, the logo's media usage link, an unknown or forbidden `tab`) land on a
   section the user may see — Task 8 tests `old tab keys open the merged sections`, `unknown or forbidden tab
   falls back to the first visible section`.

---

### Task 1: Setting definitions registry in `SiteSettings`

**Files:**
- Modify: `src/Support/Settings/SiteSettings.php` (constants `CONFIG_PATHS`, `CASTS`, `ENCRYPTED`, `STORED_ONLY`
  removed; `NO_MAX_SIDE` stays private)
- Create: `src/Support/Settings/SettingDefinition.php`
- Modify: `src/HataServiceProvider.php` (new `defineSettings()` called from `register()`, after the
  `SiteSettings` singleton binding)
- Test: `tests/Feature/Support/SiteSettingsDefinitionsTest.php`

**Interfaces:**
- Produces: `final readonly class SettingDefinition(string $key, ?string $config, ?string $cast, bool $encrypted)`
  (`$cast`: `null|'int'|'bool'|'array'`; `$config === null` = stored-only).
- Produces: `SiteSettings::define(string $key, ?string $config = null, ?string $cast = null, bool $encrypted = false): static`;
  `SiteSettings::definition(string $key): ?SettingDefinition`; `SiteSettings::isDefined(string $key): bool`.
  `put()` and `putStoredOnly()` keep their signatures; `putStoredOnly()` accepts only keys whose `$config` is null.

- [ ] **Step 1: Write the failing tests** in `SiteSettingsDefinitionsTest.php`:
  - `hata defines every key it used to` — for each of today's 42 keys (copy the list from the old `CONFIG_PATHS`
    and `STORED_ONLY`), `definition($key)` is not null with the same config path; `mail_password` and
    `media_s3_secret` are encrypted; the old `CASTS` map still holds.
  - `a defined key is stored, applied and cast` — `define('shop_currency', 'shop.currency')`, `put(['shop_currency' => 'UAH'])`
    → `config('shop.currency') === 'UAH'`; `define('shop_tags', 'shop.tags', 'array')`, `put(['shop_tags' => ['a','b']])`
    → `config('shop.tags') === ['a','b']` after a fresh `apply()` with the cache cleared (stored as JSON).
  - `a key defined after apply is applied at once` — store a row `shop_currency = UAH` directly, `apply()`, then
    `define('shop_currency', 'shop.currency')` → `config('shop.currency') === 'UAH'`.
  - `putting an undefined key throws while debugging` — `expect(fn () => $settings->put(['nope' => 1]))->toThrow(LogicException::class)`;
    with `app.debug` false and env `production`, it is skipped and `Log::warning` was called.
  - `stored rows with an unknown key are ignored` — row `gone_module_key` present, `apply()` does not throw and
    `stored()` still returns it.
- [ ] **Step 2: Run** `vendor/bin/pest tests/Feature/Support/SiteSettingsDefinitionsTest.php` — expected FAIL
  (`define` undefined).
- [ ] **Step 3: Implement.** Definitions live in a private array on the singleton. `apply()` iterates definitions
  with a config path; `define()` after the first `apply()` in this process applies that one key (remember
  `$applied = true` in `apply()`). The `array` cast: `put()` stores `json_encode`, `cast()` decodes. Mistakes
  follow `AdminMenu::problem()` (throw in `local`/`testing`/`app.debug`, else `Log::warning` and skip).
  `defineSettings()` in `HataServiceProvider` re-declares today's keys one call each, grouped by section with a
  comment per group.
- [ ] **Step 4: Run** the new test file and `vendor/bin/pest tests/Feature/Admin/Settings tests/Feature/Admin/Media`
  — expected PASS (the old page still uses the same keys).
- [ ] **Step 5: Run** `composer types:check` — expected no errors. **Commit** `Define site setting keys through a registry`.

---

### Task 2: `SettingsSections` registry

**Files:**
- Create: `src/Support/Admin/SettingsSections.php`, `src/Support/Admin/SettingsSection.php`
- Modify: `src/HataServiceProvider.php` (singleton binding in `register()`)
- Test: `tests/Feature/Admin/Settings/SettingsSectionsTest.php`

**Interfaces:**
- Produces: `final readonly class SettingsSection(string $key, string $label, string $icon, string $component, string|Closure|null $permission = 'settings.edit', int $order = 100)`.
  `label` is passed through `__()` when rendered. `permission`: ability name, `Closure(AdminUser): bool`, or null
  (any admin).
- Produces: `SettingsSections::add(SettingsSection $section): static`, `remove(string $key): static`,
  `for(AdminUser $user): list<SettingsSection>`, `visibleTo(AdminUser $user): bool`,
  `find(string $key): ?SettingsSection`, `allows(AdminUser $user, SettingsSection $section): bool`.

- [ ] **Step 1: Write the failing tests:** order by `order` then insertion; same key replaces; `remove`; `for()`
  hides a section whose ability the user lacks and one whose closure returns false; `visibleTo()` false for a
  user with none; a key that is not `^[a-z0-9]+(-[a-z0-9]+)*$` and a component Livewire cannot resolve throw
  `LogicException` naming the key in `testing`, and are skipped with a logged warning in production.
- [ ] **Step 2: Run** `vendor/bin/pest tests/Feature/Admin/Settings/SettingsSectionsTest.php` — expected FAIL.
- [ ] **Step 3: Implement** modelled on `src/Support/Admin/AdminMenu.php` (same `problem()` behaviour; check the
  component in `for()`, lazily, so registration in `boot()` before Livewire is ready never fails). No query.
- [ ] **Step 4: Run** the test — expected PASS. `composer types:check` clean.
- [ ] **Step 5: Commit** `Add the settings sections registry`.

---

### Task 3: `SettingsSectionPage` base class, section wrapper component, fixture section

**Files:**
- Create: `src/Livewire/Admin/Settings/SettingsSectionPage.php`
- Create: `resources/views/components/admin/settings-section.blade.php` (`<x-hata::admin.settings-section>`)
- Create: `tests/fixtures/ShopSettingsSection.php` (class `Tests\Fixtures\ShopSettingsSection`, inline `render()`)
- Test: `tests/Feature/Admin/Settings/SettingsSectionPageTest.php`

**Interfaces:**
- Produces (abstract `SettingsSectionPage extends Livewire\Component`):
  - `public array $values = []`; `#[Locked] public array $saved = []`.
  - `abstract public static function key(): string` — the section key, used to look up its `SettingsSection`.
  - `abstract protected function fields(): array` (list of setting keys); `abstract protected function rules(): array`
    (keys prefixed `values.`).
  - `protected function load(): array` — default: for each field, `config($definition->config)` (stored value for
    stored-only); secrets `''`.
  - `protected function secretFields(): array` — default `[]`.
  - `protected function prepare(array $validated): array` — default: blank → null; subclasses cast.
  - `protected function persist(array $changed): void` — default `app(SiteSettings::class)->put($changed)`.
  - `protected function authorizeSection(): void` — 403 unless `SettingsSections::allows(user, find(static::key()))`.
  - `public function mount(): void` — `authorizeSection()`, `$this->values = $this->load()`, `$this->saved = $this->values`.
  - `public function save(): void` — `authorizeSection()`, validate, `changed()` (fields whose raw value differs
    from `$saved`, plus typed secrets), `persist(prepare(only changed))` unless empty, `Toast::success(...)`,
    `redirectRoute('admin.settings.edit', ['tab' => static::key()])`.
  - `public function hydrate(): void` — calls `authorizeSection()`, so every request (any action, any property
    update) re-checks the permission.
- Produces: `<x-hata::admin.settings-section :heading :description :secrets>` — the section's root: Alpine
  `x-data` with `isDirty()` comparing `$wire.values` with `$wire.saved` (a typed secret counts), root attribute
  `data-settings-section`; slot for the cards; the Save button in a footer (`:save="false"` hides it for sections
  that act at once).
- Consumes: Task 1 `SiteSettings::definition()`, Task 2 `SettingsSections::allows()`/`find()`.

- [ ] **Step 1: Write the failing tests** with the fixture (fields `shop_currency` → `shop.currency`,
  `shop_api_token` encrypted secret → `shop.token`; permission `shop.settings` registered through
  `PermissionRegistry` in the test; section registered with `SettingsSections::add`, component registered with
  `Livewire::component('shop-settings', ShopSettingsSection::class)`):
  - `it loads the values in effect` — `config(['shop.currency' => 'EUR'])` → `values.shop_currency === 'EUR'`.
  - `save stores only changed fields` — change currency → one row `shop_currency`, no `shop_api_token` row.
  - `save without changes stores nothing` — `Setting::count()` unchanged.
  - `an empty secret keeps the stored one, a typed one is stored encrypted`.
  - `validation errors keep everything unsaved`.
  - `mount is 403 without the section permission`.
  - `every action re-checks the section permission` — mount as a permitted user, revoke `shop.settings`, `call('save')`
    → `assertForbidden()`.
  - `the section root carries the dirty contract` — rendered HTML contains `data-settings-section`.
- [ ] **Step 2: Run** `vendor/bin/pest tests/Feature/Admin/Settings/SettingsSectionPageTest.php` — expected FAIL.
- [ ] **Step 3: Implement** the class and the component (translations: `hata::site_settings.saved` exists — reuse;
  add only what is missing, in both languages).
- [ ] **Step 4: Run** the test — PASS; `composer types:check` clean.
- [ ] **Step 5: Commit** `Add the settings section base class`.

---

### Task 4: Site, Access and Email sections

**Files:**
- Create: `resources/views/livewire/admin/settings/sections/site.blade.php`, `access.blade.php`, `mail.blade.php`
  (SFCs: `new class extends SettingsSectionPage`)
- Create: `src/Livewire/Admin/Settings/MailSection.php` only if the test-email logic exceeds the SFC comfortably
  (builder's call; it must stay PHPStan-visible if it holds logic beyond markup)
- Modify: `src/Actions/Settings/UpdateSiteSettings.php` (no more `SiteSettingsForm` import; it receives only
  changed keys)
- Modify: `src/HataServiceProvider.php` (register the three sections)
- Test: move the General, Access and Email cases of `tests/Feature/Admin/Settings/SiteSettingsPageTest.php` into
  `tests/Feature/Admin/Settings/SiteSectionTest.php`, `AccessSectionTest.php`, `MailSectionTest.php`, targeting
  `hata::admin.settings.sections.site` etc.

**Interfaces:**
- Consumes: Task 3 base. Sections' `persist()` call `UpdateSiteSettings::handle($actor, $changed)`.
- Produces: component names `hata::admin.settings.sections.{site,access,mail}`; `key()` = `site`, `access`, `mail`.

- [ ] **Step 1: Move the tests** (retarget, keep every assertion) and add `untouched site name keeps following config`
  (save timezone only → no `site_name` row) and, for mail, `switching to the server mailer removes the smtp rows`
  (the `normalizeMail` rule still fires because `mail_mailer` is a changed key).
- [ ] **Step 2: Run** the three files — FAIL (components missing).
- [ ] **Step 3: Implement** by moving the markup and rules from `edit.blade.php` (`@if ($tab === 'general'|'access'|'mail')`
  blocks; rules and casts from `SiteSettingsForm::rules()`/`validateSection()`; the mail `fillFromSettings` branch
  into `load()`; `sendTestEmail` with its rate limit into the mail section). Labels: rename translation group
  `site_settings.general` → `site_settings.site` (both languages; update every reference).
- [ ] **Step 4: Run** the three files and `vendor/bin/pest tests/Feature/Localization` — PASS.
- [ ] **Step 5: Commit** `Move the site, access and email settings into sections`.

---

### Task 5: Languages and Content types sections

**Files:**
- Create: `resources/views/livewire/admin/settings/sections/languages.blade.php`, `content.blade.php`
- Move: `resources/views/partials/admin/settings/languages.blade.php`, `languages-modals.blade.php`,
  `content-types.blade.php` stay as partials included by the new sections (or are inlined; builder's call)
- Modify: `src/HataServiceProvider.php` (register both)
- Test: move the language and content-type cases of `SiteSettingsPageTest.php`,
  `tests/Feature/Localization/ContentLanguagesTest.php` and `tests/Feature/Admin/Content/ContentTypeSwitchTest.php`
  that drive the page to the new components.

**Interfaces:**
- Produces: `hata::admin.settings.sections.languages` (fields: `default_locale`; the add/toggle/default/remove
  actions apply at once, as today) and `hata::admin.settings.sections.content` (no fields, no Save:
  `<x-hata::admin.settings-section :save="false">`).

- [ ] **Step 1: Move the tests**; every language and content-type action also gets `authorizeSection()` coverage
  through the existing permission tests.
- [ ] **Step 2: Run** them — FAIL.
- [ ] **Step 3: Implement** by moving the methods (`siteLanguages`, `catalogLanguages`, `addLanguage`,
  `toggleLanguage`, `confirmLanguage`, `runLanguageAction`, `changeLanguages`, `contentTypeRows`,
  `toggleContentType`, `turnOffContentType`, `changeContentType`) and their modals; redirects go to `tab=languages`
  / `tab=content` as today.
- [ ] **Step 4: Run** — PASS.
- [ ] **Step 5: Commit** `Move languages and content types into sections`.

---

### Task 6: Appearance section (logos + colors, one Save)

**Files:**
- Create: `resources/views/livewire/admin/settings/sections/appearance.blade.php`
- Modify: `src/Actions/Settings/SetLogo.php`, `RemoveLogo.php` (unchanged rules; they are now called from
  `persist()` inside one `DB::transaction` with the color `put()`)
- Modify: `src/Models/Setting.php:44` (`tab` → `appearance`)
- Modify: `src/HataServiceProvider.php` (register)
- Test: move `tests/Feature/Admin/Settings/SiteLogoTest.php` and the Colors cases of `SiteSettingsPageTest.php`
  into `tests/Feature/Admin/Settings/AppearanceSectionTest.php`; the cases that asserted "stored as soon as
  chosen" become "stored on Save" (the spec supersedes decision 49 on this point).

**Interfaces:**
- Produces: `hata::admin.settings.sections.appearance`, fields `logo_light`, `logo_dark`, `color_scheme`,
  `color_accent`, `color_gray`, `color_tint_sidebar`. `removeLogo(string $variant)` (the dangling-file button)
  stays an immediate action. The color preview reads `$this->values`.

- [ ] **Step 1: Move/adapt the tests** and add `appearance saves logos and colors all or nothing` (valid new
  logo + `color_scheme = custom` with `color_accent = 'nope'` → no logo setting, no usage row, colors unchanged)
  and `picking a logo makes the section dirty` (rendered `isDirty` contract: `values.logo_light` differs from
  `saved.logo_light`).
- [ ] **Step 2: Run** `vendor/bin/pest tests/Feature/Admin/Settings/AppearanceSectionTest.php` — FAIL.
- [ ] **Step 3: Implement.** Validation of a logo id runs before the transaction (as `SetLogo` does today, via
  `MediaSelection`); `persist()` wraps `SetLogo`/`RemoveLogo` for changed logo fields and `UpdateSiteSettings`
  for changed colors in one `DB::transaction`.
- [ ] **Step 4: Run** the file and `vendor/bin/pest tests/Feature/Admin/Media` — PASS.
- [ ] **Step 5: Commit** `Merge logo and colors into the appearance section`.

---

### Task 7: Media section

**Files:**
- Create: `resources/views/livewire/admin/settings/sections/media.blade.php` (split into partials under
  `resources/views/partials/admin/settings/media/` if it passes ~300 lines)
- Create: `src/Livewire/Admin/Settings/MediaSection.php` (abstract, extends `SettingsSectionPage`: storage test,
  S3 confirmation, storage link, optimization card, server cap — the logic now in `edit.blade.php` lines ~579-830)
- Modify: `src/Actions/Settings/UpdateSiteSettings.php` (`STORAGE_FIELDS` becomes its own public const; the
  page-side "only changed storage fields" filter is gone, the base class does it for every field)
- Modify: `src/HataServiceProvider.php` (register)
- Test: move `tests/Feature/Admin/Settings/MediaSettingsTest.php` and `MediaStorageSettingsTest.php` to target
  `hata::admin.settings.sections.media`.

**Interfaces:**
- Produces: `hata::admin.settings.sections.media`; `MediaSection::MEDIA_MAX_SIDES` (moved from
  `SiteSettingsForm`); `UpdateSiteSettings::STORAGE_FIELDS`.

- [ ] **Step 1: Move the tests** (all 36 cases keep their assertions; ones that set `tab` drop it).
- [ ] **Step 2: Run** both files — FAIL.
- [ ] **Step 3: Implement** by moving the media markup, the media `load()` mapping (`max_side` `'none'`,
  `media_storage` `public|s3`), the casts, `filesOnOtherDiskCount` (still `#[Locked]`, still computed once per
  mount), `testStorageConnection`, `confirmS3Change`, `createStorageLink`.
- [ ] **Step 4: Run** both files and `vendor/bin/pest tests/Feature/Admin/Media` — PASS.
- [ ] **Step 5: Commit** `Move media settings into a section`.

---

### Task 8: The shell page, menu item, aliases; remove the monolith

**Files:**
- Rewrite: `resources/views/livewire/admin/settings/edit.blade.php` (shell, target under 150 lines)
- Delete: `src/Livewire/Forms/SiteSettingsForm.php`
- Modify: `src/HataServiceProvider.php` (menu item `settings`: closure `admin.access && SettingsSections::visibleTo($user)`)
- Test: what remains of `SiteSettingsPageTest.php` (page-level cases: tabs listed, unsaved guards in the markup,
  leaving) becomes `tests/Feature/Admin/Settings/SettingsShellTest.php`; `tests/Feature/Admin/AdminMenuTest.php`
  updated only if its expectations change.

**Interfaces:**
- Consumes: Tasks 2–7. Shell: `#[Url] public string $tab`; `mount()` → 403 unless `visibleTo()`; resolves
  aliases, then falls back to the first of `for($user)`; renders the nav as `wire:navigate` links to `?tab=`;
  renders `<livewire:dynamic-component :is="$section->component" :key="$section->key" />`; holds the one browser
  guard (`livewire:navigate` + `beforeunload` + the "Leave without saving" modal) asking
  `document.querySelector('[data-settings-section]')` for `isDirty()`.

- [ ] **Step 1: Write the failing tests:**
  - `the page lists the seven sections in order` (labels from the rendered nav, as `AdminMenuTest` reads the sidebar).
  - `old tab keys open the merged sections` — `?tab=general` → site, `?tab=logo` and `?tab=colors` → appearance.
  - `unknown or forbidden tab falls back to the first visible section`.
  - `a module-only user sees the menu item and only the module section` (fixture from Task 3; user with
    `admin.access` + `shop.settings`, no `settings.edit`).
  - `a hata section is 403 for a module-only user` — `Livewire::test('hata::admin.settings.sections.mail')` as
    that user → forbidden.
  - `a user with no section gets 403 and no menu item`.
  - `the shell renders only the open section` — response for `?tab=site` does not contain the media section's
    markers (e.g. `data-test="media-storage"`).
- [ ] **Step 2: Run** `vendor/bin/pest tests/Feature/Admin/Settings/SettingsShellTest.php` — FAIL.
- [ ] **Step 3: Implement** the shell; delete `SiteSettingsForm` and every reference; `grep -rn "SiteSettingsForm\|switchTab\|pendingTab" src resources tests`
  returns nothing.
- [ ] **Step 4: Run** `composer test` — expected all green (Pint, PHPStan, full suite). Then the MySQL suite once
  (env from `phpunit.xml` overridden to the Herd MySQL), not at the same time as SQLite.
- [ ] **Step 5: Commit** `Render site settings as registered sections`.

---

### Task 9: Docs, browser check, release

**Files:**
- Modify: `docs/specs/site-settings.md` (sections, registry, per-section access, changed-only Save, Appearance),
  `docs/specs/package.md` ("Adding a settings section": `define()`, a component extending `SettingsSectionPage`,
  `SettingsSections::add()` in `boot()`, its own permission via `PermissionRegistry`), `docs/specs/media.md`
  (logo usage link), `docs/specs/admin-menu.md` (settings item permission), `CLAUDE.md` (decision 75: sections and
  key registry, per-section access, changed-only Save; note on decision 49 that logos are saved by Save).

- [ ] **Step 1: Write the docs.** Commit `Document settings sections`.
- [ ] **Step 2: Browser check with the user** (memory: UI first): in `/Users/vitalii/Herd/hata-demo` temporarily
  add the path repository back (`"repositories": [{"type": "path", "url": "../hata"}]`, `composer require
  sliusardev/hata:@dev`), let the user walk all seven sections, switching with unsaved changes, leaving, logo +
  color save. Fix what they find (each fix with its test). Restore the demo's Packagist requirement afterwards.
- [ ] **Step 3: Merge on the user's word**, push `main`, tag and push `v0.2.0`, then `composer update
  sliusardev/hata` in the demo.

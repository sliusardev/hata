# Site settings: sections a site can extend

2026-11-24. Done before Part 3 (`2026-11-24-seo-sitemap-home-design.md`), whose new fields land in the "Site"
section built here.

## Intent

The Site settings page (`/admin/settings`, `hata::admin.settings.edit`) is one Livewire component of 1328 lines
holding every tab's form in one `SiteSettingsForm`, and the setting keys are constants in `SiteSettings`. Part 3
would add a ninth tab. The user wants (their words): fewer tabs, code that stays fast and maintainable, and, in
the future, a site that installed Hata able to add its own settings to this page.

## Goals

1. Fewer tabs: General and Part 3's SEO become **Site**; Logo and Colors become **Appearance**. Seven sections:
   Site, Languages, Content types, Access, Email, Appearance, Media.
2. Each section is its own Livewire component; the page is a shell that renders only the open one. A request
   carries one section's state, not all eight.
3. A registry of sections (`SettingsSections`), used by Hata's own sections and by a site or module the same way
   (as `AdminMenu`).
4. A registry of setting keys in `SiteSettings` (config path, cast, encrypted, stored-only) instead of the
   constants, so a site's or module's own settings are stored, cached and applied over config by the same
   mechanism.
5. A base class for a section that gives the unsaved-changes guard, Save, the toast and the redirect, so a site's
   section is a few fields and rules.

## Non-goals

- New settings (they come with Part 3).
- A generator (`make:settings-section`); the package spec documents the hand-written form.
- Changing what any setting does, how it is stored, or its permission (`settings.edit` stays).

## Anti-goals

- No behaviour lost: every rule in `docs/specs/site-settings.md` (validation, the S3 confirmation and test, the
  test email, languages and content types switching at once, the color preview, storage of untouched storage
  fields, the unsaved guards) keeps working; tests are moved, not dropped.
- No cost added to other admin pages; the registries make no queries.
- A site's section never needs to edit or override a Hata file.

## Approaches (chosen with the user)

- **A, chosen:** a shell plus one component per section, a section registry and a key registry; Hata's sections
  use the same API a site would. Done as its own step before Part 3.
- B, rejected: keep Hata's tabs in the monolith and add a registry only for a site's extra tabs. Two mechanisms,
  and the weight of every request and the 1300-line file stay.
- C, rejected: merge the tabs now, extensibility later. Part 3's section would be written twice.

## Design

### Sections registry

`Hata\Support\Admin\SettingsSections` (singleton), modelled on `AdminMenu`:

- `add(SettingsSection $section)`; the same key replaces an earlier one. `remove(string $key)`.
- `SettingsSection(key, label, icon, component, permission = 'settings.edit', order = 100)`: `label` a
  translation key or plain string; `icon` a Flux icon; `component` a Livewire component name or class;
  `permission` an ability or `Closure(AdminUser): bool`.
- `for(AdminUser $user)`: the sections the user may see, by `order`, ties in the order added.
- Hata's own, registered in `HataServiceProvider`: `site` 10, `languages` 20, `content` 30, `access` 40, `mail` 50,
  `appearance` 60, `media` 70. A site registers its own in a provider's `boot()`.
- **Access is per section** (the user's choice, from the builder check): the permission of a section is a real
  gate, not only a filter inside `settings.edit`. Hata's own sections keep `settings.edit`; a module's section
  may use its own ability (`shop.settings`), so a shop manager changes the currency without SMTP, S3 or
  registration. `SettingsSections::visibleTo(AdminUser $user): bool` is true when `for($user)` is not empty.
- Mistakes (a component that does not exist, a key that is not slug-shaped) throw a `LogicException` in `local`,
  `testing` and with `app.debug`, else the section is skipped and a warning logged, as in `AdminMenu`.

### Setting keys registry

`SiteSettings::define(string $key, ?string $config = null, ?string $cast = null, bool $encrypted = false)`:
`$config` null means stored-only (the probe results today). Hata's keys move from the constants
(`CONFIG_PATHS`, `CASTS`, `ENCRYPTED`, `STORED_ONLY`) into definitions made in `HataServiceProvider::register()`.
A site or module defines its keys in its provider's `register()`, because the stored settings are applied in
Hata's `boot()` and every `register()` runs before any `boot()`; a key used before it is defined throws in the
same environments as above. Stored rows with an unknown key are ignored (a module that was removed). Everything
else in `SiteSettings` (cache, memo, apply, `buildingConfigCache()`, the defaults restored on removal) is
unchanged. The `media_max_side` 0 ↔ null rule stays a special case inside `SiteSettings`.

### The page

- Route and name stay (`admin.settings.edit`, `?tab=`). The shell (`hata::admin.settings.edit`) lets in a user
  with at least one visible section (403 otherwise), lists the user's sections (side list from a tablet up, scrolling row on a phone, as now) and
  renders the open one with `<livewire:dynamic-component>` keyed by the section. An unknown or forbidden `tab`
  falls back to the user's first section.
- The sidebar item "Site settings" uses a closure permission: `admin.access` and `SettingsSections::visibleTo()`
  (no query: sections are an array, abilities are the user's loaded permissions). Old keys keep working: `general` → `site`, `logo` and `colors` → `appearance`
  (links in old toasts, bookmarks, the media usage link of a logo).
- Switching sections is navigation (`wire:navigate` to `?tab=…`), not a Livewire action, so the browser-side guard
  that already covers leaving the page (Alpine `isDirty()` on `livewire:navigate`, `beforeunload`, the "Leave
  without saving" dialog) covers switching too, and the server-side `switchTab`/`pendingTab`/`savedValues` of the
  shell go away. The guard lives once in the shell and asks the open section whether it is dirty through a
  small browser contract the base class provides (the section's root element exposes its form and saved values).
- Each section component renders its own heading, card(s), Save button and dialogs.

### The section base class

`Hata\Livewire\Admin\Settings\SettingsSectionPage` (abstract Livewire component; a class, so PHPStan analyses it,
see decision 32). It holds `$values` (the section's fields) and the locked `$saved` copy, and provides:

- `fields(): list<string>` and `rules(): array` (abstract), `mount()` filling `$values` with the values in
  effect (stored over config/.env, what the form shows today; a stored-only key from its stored value);
- Save stores **only the fields that differ from what was loaded**, so an untouched value that comes from `.env`
  or a config default is never frozen into the database (today's rule for the storage fields, made general; my
  call, from the builder check). A field changed back to the config value is still stored (no guessing);
- `define()` also takes the cast `array` (stored as JSON), needed by Part 3's `site_description`;
- the section's permission checked in `mount()` and again on every action (a request can name any component),
  through one `authorizeSection()` the base class calls before each public action it defines; a subclass's own
  actions call it too;
- `save()`: authorize, validate, `persist(array $values)` (default: `SiteSettings::put()` per key, in one
  transaction; Hata's sections override it to call their actions such as `UpdateSiteSettings`), then the success
  toast and a redirect to the same section;
- secret fields (`secretFields()`): never loaded, stored only when typed, always count as a change (today's
  `mail_password`, `media_s3_secret` rule);
- the browser contract for the dirty check.

Hata's sections extend it with their extra actions (test email, storage test and S3 confirmation, language and
content type actions that apply at once). Their logic lives in classes under `src/Livewire/Admin/Settings/`, the
markup in views under `resources/views/livewire/admin/settings/` (builder's call between class-based components
with a view and SFCs over a base class, following `EntryFormPage`). `SiteSettingsForm` is split up between them
and removed.

### Appearance section

Logos and colors on one section with one Save. The logo pickers become fields of the form: picking or removing a
logo is a change the guard sees, and Save applies it through `SetLogo` / `RemoveLogo` (their rules, the `media.view`
check and the usage record unchanged), then the colors, all in one transaction: either everything on the section is saved or nothing (my call). The
"logo file is missing" remove button stays an
immediate action. The color preview is unchanged.

### Site section

Site name and timezone now; Part 3 adds the home page, the description per language and the image for sharing.

### Performance

- The shell does no query beyond today's page; only the open section mounts, so the queries a section needs (the
  Media tab's counts, the role options, the language list) run only when it is open, as today, and the payload of
  every request in a section holds only that section's fields.
- The registries are arrays filled at boot; no query, no cache read.

### For a site

`docs/specs/package.md` gets "Adding a settings section": define keys in `register()`, write a component extending
`SettingsSectionPage` with `fields()`/`rules()` and a view, register it with `SettingsSections::add()` in `boot()`,
translations in the site's own files. A test in Hata registers a fixture section with a fixture key and proves the
round trip (shown to a permitted user, saved, applied over config on the next request, guarded when dirty, hidden
from a user without its permission), and a user with only the fixture's permission (no `settings.edit`) who sees
the menu item and the page with that one section, gets 403 on Hata's sections by `?tab=` and by a direct Livewire
call to their components.

## Compatibility and release

- `?tab=general|logo|colors` keep working (mapped). The `SiteSettings` constants are removed: a breaking change for
  code that read them, released as `v0.2.0` (0.x: a breaking change raises the minor).
- Specs: `site-settings.md` rewritten for the sections, `package.md` (extension API, what changed), `media.md`
  (logo usage link), CLAUDE.md decision for the registry and the merge; decision 49's "stored as soon as it is
  chosen" for logos is superseded.

## Left to the builder

- Class-based components vs SFCs over the base class for Hata's own sections.
- How the browser contract for the dirty check is shaped.
- How the existing tests are regrouped per section (each existing assertion must survive, retargeted at the
  section component).
- Splitting the Media section's view further (it is the largest).

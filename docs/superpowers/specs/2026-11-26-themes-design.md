# Themes: design

Status: draft for the user's approval (2026-11-26). Release 1 of two; site menus are release 2.

## Intent and why

A site on Hata must look like that site. Today the public side is five built-in Blade views and one layout; a
page, a post and a product are all drawn by the same `site.entry`, a type's own fields are never shown, and the
only way to change anything is to override a whole Hata view for every type at once. The user keeps running into
this ("ми ввесь час упираємось в шаблони і як показати контент"), and wants themes "як у WordPress або Joomla":
a folder of Blade templates that is easy to create, switched in the admin, with templates bound to content types,
taxonomies and single pages, its own layouts, its own classes, functions and data, and its own settings fields.

## Goals

- A theme is a folder in the site. Nothing is installed, uploaded or unpacked: the folder exists, the theme is
  listed.
- The template of a page is chosen by a chain from the most specific to the most general, ending at Hata's
  built-in views, so a theme can start with one layout and one file.
- An editor can give one record a template of its own ("кожна сторінка могла мати свій вигляд").
- A theme has its own layouts, its own PHP classes and functions, and gives its templates data.
- A theme declares settings fields; the admin draws their form; templates read the values.
- An admin page lists the themes (as WordPress does), activates one, and opens each theme's settings.
- `php artisan make:theme <key>` writes a working starter theme.
- No query and no measurable cost is added to a public page by the theme mechanism itself.

## Non-goals (this release)

- Site menus built in the admin, and areas for blocks or widgets (release 2, on this base).
- Installing a theme from an archive, a marketplace, child themes.
- Editing theme strings in the admin (strings are files in this release).
- A live customizer. (A plain preview of an inactive theme is in scope, see "Preview".)
- Default templates brought by a module (the resolver takes a list of view namespaces, so this is a small later
  addition; nothing registers a second one now).

## Anti-goals

- A theme must never need to edit or override a Hata file, and Hata's updates must never overwrite a theme.
- Public pages stay plain Blade: no Livewire, no Flux, no admin JavaScript (decision 59), whatever the theme.
- A theme's look never depends on an admin setting: the admin's colour scheme does not reach the public side.
- The theme mechanism must not scan folders or query the database on a public request.
- A broken or deleted theme must not take the site down: the site falls back to the built-in views.

## Constraints

- PHP 8.4, Laravel 13, Blade. Sites may be on shared hosting without a console, npm or symlinks.
- The site's own files are the site's: Hata reads `themes/`, it writes there only through `make:theme`.
- The suite passes on SQLite, MySQL and PostgreSQL; a shipped migration is never edited (a new one is added).
- Every new admin string in `en` and `uk`; the admin screens are shown to clients and must look finished.

## Approaches considered

1. **A theme folder in the site with a template chain and a fallback to the built-in views** (chosen by the
   user). Gives the chain, per-record templates, settings, switching, and a theme that can be partial.
2. **Only Laravel's view overrides** (`resources/views/vendor/hata/site/…`, what exists today). The simplest, but
   one file per kind of page for every type at once, no per-record template, no settings, no switching.
3. **A theme only as a Composer package.** Contradicts "nothing is installed"; a folder registered by path
   covers the same case later.

## What the user decided

- Themes live in **`themes/<key>/` at the site's root**.
- Styles are **plain CSS** served from the theme's `assets/`; a theme **may** build its files with the site's own
  Vite and npm, which is the site's business (Hata does not take part in that build).
- **Menus are release 2.**
- The admin has **a page of its own for themes**: a list of the themes as in WordPress, and each theme's
  settings opened from it.

## The theme folder

```
themes/nyam/
  theme.php                       the manifest (required): what makes a folder a theme
  functions.php                   optional: plain functions, required once when the theme is in use
  src/                            optional: the theme's classes, namespace Themes\Nyam\
    Theme.php                     optional: boots the theme (view composers, anything else)
  layouts/main.blade.php          layouts, as many as the theme wants
  home.blade.php                  the home page
  entry.blade.php                 any record without a closer template
  list.blade.php                  any type's list without a closer template
  term.blade.php                  any term's page without a closer template
  types/<type key>/entry.blade.php
  types/<type key>/list.blade.php
  terms/<taxonomy key>.blade.php
  templates/<name>.blade.php      templates an editor may pick for one record
  errors/404.blade.php            (and 403, 500, 503 if the theme wants them)
  partials/…                      whatever the theme includes
  lang/<locale>.php               the theme's strings
  assets/…                        css, js, images, fonts; the screenshot
```

The key is the folder name: lowercase letters, digits and hyphens. Only `theme.php` is required.

### The manifest

`theme.php` returns an array. It is PHP, so settings are declared with the same field classes content types use.

```php
return [
    'name' => 'Nyam',
    'description' => 'The shop\'s own look.',
    'version' => '1.0.0',
    'author' => 'Studio',
    'screenshot' => 'screenshot.png',                 // a file in assets/
    'class' => \Themes\Nyam\Theme::class,              // optional
    'templates' => [                                   // what an editor may pick for a record
        'landing' => ['label' => 'Landing page', 'types' => ['pages']],   // no 'types' = every type
    ],
    'settings' => [
        Text::make('phone'),
        MediaFile::make('hero_image'),
        Textarea::make('footer_text')->translatable(),
        Toggle::make('show_prices'),
    ],
];
```

A manifest that cannot be loaded, or whose values have the wrong shape, makes the theme "broken": it is listed
with the reason and cannot be activated; if it is the active one, the site uses the built-in views and a user
with the themes permission sees why on the themes page.

### The theme's code

- `src/` is autoloaded as `Themes\<StudlyKey>\` for the theme in use (the active one, or the one being
  previewed or configured), by one autoloader Hata registers for that folder. No Composer change.
- `functions.php`, when present, is required once for the theme in use.
- `class` names a class extending `Hata\Themes\Theme` with one hook, `boot(): void`, called when the theme is
  in use on a public request. Data reaches templates the Laravel way: the theme registers view composers
  there (`View::composer('theme::home', HomeData::class)`), so a query runs only when that template is drawn.
- A theme without `class`, `src/` and `functions.php` is only templates; that is a complete theme.

## Choosing the template

The theme in use is registered as the view namespace `theme` (so its own includes are `theme::partials.card`,
its layouts `theme::layouts.main`) and its `lang/` as the translation namespace `theme`. One resolver,
`Hata\Themes\ThemeViews`, answers the first view that exists, and remembers the answer for the request:

| Page | Chain (first that exists) |
|---|---|
| A record | `theme::templates.<the record's template>` → `theme::types.<type>.entry` → `theme::entry` → `hata::site.entry` |
| A term | `theme::templates.<template>` → `theme::terms.<taxonomy>` → `theme::term` → `hata::site.term` |
| A type's list | `theme::types.<type>.list` → `theme::list` → `hata::site.list` |
| Home, a page is chosen and public | `theme::templates.<the record's template>` → `theme::home` (with the record's variables) → the record's own chain |
| Home, no page chosen, or not public here | `theme::home` → `hata::site.home` / `hata::site.home-plain` (as today) |
| An error on a public address | `theme::errors.<status>` → Laravel's own |

With no theme chosen every chain is its last step: the site looks exactly as it does today, and a site's
existing overrides in `resources/views/vendor/hata/site/…` keep working, because the last step is still
Hata's view name.

### What a template receives

The same variables the built-in views receive today (they become a documented contract): for a record or a
term `entry`, `type`, `body` (the rendered HTML), `galleries`, `terms`, `records` (a term's or a taxonomy
page's paginator), `preview`, `languageOff`, `editUrl`; for a list `type`, `records`; for the home page the
chosen record's variables, or none. Every template also gets `$theme` (`Hata\Themes\ActiveTheme`): `key()`,
`setting('phone')`, `asset('css/site.css')`. Global helpers `theme('phone')` and `theme_asset('css/site.css')`
do the same from anywhere. A type's own fields are read with the existing `$entry->fieldValue('sku')`.

The layout prints the head with the existing `@hataHead`; the language switcher and the editor's preview strip
stay Hata partials a theme may include (`hata::partials.site.language-switcher`, `…preview-strip`).

## A template for one record

- A new nullable column `entries.template` (a new migration), holding a key of the theme's `templates`.
- The entry form's properties card shows a "Template" select when the theme in use offers at least one
  template for the record's type; the first option is "Default". The value is validated against that list.
- The value is per record (a translation copy carries it). It is kept when the theme changes; a key the theme
  in use does not have simply falls through the chain, and the form shows it as "Default" until saved again.

## Assets

- Files in `themes/<key>/assets/` are public. `theme_asset('css/site.css')` returns
  `/themes/<key>/css/site.css?v=<the file's mtime>`.
- That address is answered two ways. When `public/themes/<key>` exists (a link to the theme's `assets/`, made
  by `php artisan themes:link` or by a button on the themes page), the web server serves the file. When it does
  not, a route without the `web` middleware group (`theme.asset`, like `media.static`) streams the file from the
  theme's `assets/` with the right content type and a week of caching for a current `v`. So a theme works on a
  hosting without links, and is static where a link is possible. Only files inside `assets/` are ever served
  (no dot files, no `..`, no PHP).
- A theme that wants a build uses the site's own Vite: its sources sit in the theme, the site's `vite.config.js`
  lists them, and the layout prints Laravel's `@vite([...])`. Hata does nothing there; the docs show the setup.

## Theme settings

- Declared in the manifest with content field classes (`Text`, `Textarea`, `RichText`, `Number`, `Date`,
  `Toggle`, `Select`, `MediaFile`, `Gallery`), drawn with the same field views the entry form uses, and a new
  `Color` field (a colour picker with the hex value beside it, validated as `#rrggbb`; usable by content types
  as well).
- A new field option `translatable()`: the value is kept per content language and `theme('footer_text')`
  answers the current language's, falling back to the default language's. (For theme settings only in this
  release; a content type's field that calls it is a registration mistake.)
- Stored in the `settings` table, one row per theme (`theme.<key>`), as JSON; loaded with the site settings, so
  reading a theme's settings costs no query. The values of an inactive theme are kept.
- A file field records its usage in the media library as the logos do (decision 49), so the library shows
  "Theme setting: <label>" and deleting the file empties the setting.
- A setting whose field left the manifest is ignored and dropped on the next save.

## The admin

- A page **Themes** (`/admin/themes`, menu group `administration`, permission `themes.manage`, a new
  permission in a new group, given to the `admin` role by the `*` default): a card per theme with its
  screenshot, name, description, version and author; the active one marked; actions Activate, Settings,
  Preview. The first card is "Hata (built-in)", which stands for no theme. Broken themes are listed with the
  reason. Activating is confirmed and stores the setting `theme` (null for the built-in).
- **Settings** of a theme (`/admin/themes/{key}`): the form of its declared fields, saved together; available
  for an inactive theme too, so it can be prepared before it goes live. A theme without settings has no such
  page.
- **The colour scheme of Site settings → Appearance is the admin's only** (the user's ruling). A theme never
  receives it: it brings its own colours, and offers its own schemes through its settings when it wants to
  (a `Select` of the theme's presets, or colours picked with the new `Color` field, which the theme's layout
  prints as its own CSS variables). The built-in public layout stops including the admin's scheme too and
  keeps one neutral look. The logos stay the site's: a theme prints them with `<x-hata::site-logo>` when it
  wants the uploaded ones.

### Preview

A user with `themes.manage` can open the site drawn by an inactive theme: "Preview" stores the theme's key in
their session, the public pages then use that theme for them only, under a strip ("You are previewing the theme
…", with "Stop" and "Activate"), with `noindex` and `no-store`. Guests and other users are not affected and pay
nothing: the check reads the session only when a session cookie came with the request.

## Cost per public request

- The active theme's key and its settings come with the site settings (already loaded): no query.
- No folder is scanned: the path is `themes/<key>`. Themes are listed only on the admin page.
- The manifest of the theme in use is one `require` (opcache holds it); the chain is at most four
  `file_exists` per page, remembered for the request; compiled views are cached as any Blade view.
- The page-view query bound of decision 59 and 76 does not change. A test asserts the query count of a record
  page with a theme equals the count without one.

## `make:theme`

`php artisan make:theme nyam [--name="Nyam"]` writes `themes/nyam/` with a manifest, `layouts/main`, `home`,
`entry`, `list`, `term`, `errors/404`, `partials/header` and `footer`, `assets/css/theme.css`, `lang/en.php`
and the site's other content languages, and a commented example of one setting, one per-record template and
`src/Theme.php`. The starter declares two colour presets and an accent `Color` setting and prints them as CSS
variables in its layout, as the example of a theme's own colour scheme. It is a simple, clean, readable theme in plain CSS that shows a type's fields, the
record's image and gallery, the terms, the translations switcher and pagination, so a developer edits working
markup instead of starting from nothing. It refuses to overwrite an existing folder.

## Settled after the builder check (the author's calls, for the user to confirm)

1. **A fallback view keeps the built-in look.** Hata's own views extend Hata's layout and use Hata's CSS; they
   are not re-dressed in a theme's layout (their markup is written for the built-in stylesheet). A theme covers
   every kind of page it wants to look its own; `make:theme` writes all of them, and the themes page shows, per
   theme, which kinds of page fall back to the built-in views.
2. **The home page chain.** The chosen record's own per-record template wins; otherwise `theme::home` wins over
   the type's templates and receives the record's variables (`entry` is null when no page is chosen or public).
3. **Per-record templates apply to terms too**: the select appears in a term's form when the theme offers a
   template for that taxonomy type.
4. **A stored template the theme in use does not have is kept**, never normalised: the form shows it as an
   extra option ("landing: not in this theme") so saving does not lose it, and the chain skips it. A translation
   copy carries the value; linking, unlinking and a language change leave it alone.
5. **Where the theme in use is decided.** Lazily, on the first call that needs it (`Themes::current()`), once
   per request: the stored active theme, or the previewed one when the request has a started session whose
   user may manage themes. The error pages ask the same resolver. Nothing is bound at boot.
6. **A broken theme.** Loading the manifest, `functions.php`, the class and `boot()` runs inside a guard: any
   `Throwable` marks the theme broken for the request, is logged once, and the site uses the built-in views.
   Activate and Preview load the theme the same way first and refuse with the reason when it fails. What PHP
   cannot catch (a function declared twice, a compile-time fatal) is outside the guard; the docs say so and tell
   themes to keep functions in their namespace.
7. **`themes.manage` is an ordinary permission** (not reserved like `maintenance.run`): a theme's code is put on
   the server by the site's developer, like any site code; the permission chooses among it and edits settings.
8. **Theme settings storage.** A store of its own (`ThemeSettings`) over the `settings` table: one JSON row per
   theme, read from the rows `SiteSettings` already loaded (no query), written outside the site-settings form
   machinery, never touching the sitemap marker. File usages are recorded against that `Setting` row.
9. **Assets.** The route `/themes/{key}/{path}` and the optional link `public/themes/<key>` → the theme's
   `assets/` answer the same addresses, so `?v=` URLs do not change when the link appears or goes. Without
   symlinks the link is simply not made.
10. **Fields.** The field views already bind through a `$model` path, so they are reused with the settings form's
    own property; `translatable()` goes on the `Field` base and the settings form draws one input per content
    language for such a field.

## Left to the builder

- Class and file names inside `Hata\Themes`, and how `SiteSettings` learns the per-theme key.
- How the public error pages hand over to the theme (and set the content language first: a 404 answered before
  the session is rendered before the language is set today).
- The exact look of the themes page and of the starter theme, to be settled in the user's browser pass.
- How the entry form, the sitemap and the home page setting are told about `template`.
- Tests: the chain for every kind of page, the fallback with a broken theme, per-record templates, settings
  (translatable, media usage), the asset route's safety, preview isolation, the query-count equality,
  `make:theme` output, both languages of every new string.
- The spec `docs/specs/themes.md`, a "Making a theme" guide in `docs/specs/package.md`, a decision in
  `CLAUDE.md`, and the release notes. Release as v0.6.0.

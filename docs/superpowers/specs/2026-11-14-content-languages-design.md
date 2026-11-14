# Content languages (multilingual foundation): design

Status: approved by the user and built 2026-11-14 on the branch `content-languages`. The user chose the
alternative for the interface default: the field is always on the Languages tab and "Make default" carries a
checkbox. Other differences from this text: the switch on the tab is a server-rendered button, and the tab
lists the languages as rows of a card rather than a table. The current description is in
`docs/specs/languages.md`.

## Intent

The kit will hold site content (pages, posts, themes come later) in several languages. Before any content
exists, the site needs to know which languages it speaks, which one is the default, and how a language shows
in an address. This is that foundation only.

In the user's words: a list of all available languages in the settings, of which only two or three are made
active; inactive ones are unavailable to the site until activated; there is always a default language (for
example Ukrainian); the admin works as before; the site opens the default language at `/<route>` and every
other language at `/<code>/<route>`, for example `/en/<route>`.

## Two sets of languages (decided with the user)

- **Interface languages**: unchanged. `config('app.available_locales')`, one `lang/<code>/` folder each
  (today `en`, `uk`), chosen by each user in their account settings. The user plans to add more folders once
  the system is finished.
- **Content languages**: new. Chosen in the site settings from a built-in catalogue of languages, independent
  of `lang/` folders. A site may have 12 content languages and 2 interface languages.

Why they are separate: the catalogue holds far more languages than the interface will ever be translated
into; an administrator must be able to add a content language without a deploy; and every interface language
costs a translation of every new string from then on (the parity test demands it).

The one link between the sets: a content language that also has an interface translation shows the service
pages (sign in, password reset) in that language.

## Goals

1. A built-in catalogue of languages (code, English name, native name, direction).
2. A **Languages** tab in the site settings: add a language from the catalogue, switch it on or off, choose
   the default, remove it.
3. Addresses: the default language without a prefix, every other active language under `/<code>/…`.
4. A language that is off, or not added, is not reachable: 404.
5. Guests see the service pages in the language of the site they last visited, when the interface has it.
6. A list of reserved slugs for the content types that come later.
7. Helpers for themes: the current content language, the active languages, the address of the current page
   in another language, `hreflang` alternates.

## Non-goals (later stages)

- Content models and their translations (pages, posts): stored in the database, one record per language;
  designed with the first content type.
- Theme strings ("Read more") and their admin-editable overrides: files shipped by the theme, with overrides
  from the admin stored in the database on top; designed with the themes stage.
- Translated slugs, a language per domain or subdomain, choosing a language from `Accept-Language`,
  redirecting a first-time visitor to "their" language, reordering languages by hand, previewing a language
  that is off, translating the admin into more languages.

## Anti-goals

- No query and no cookie added to a public request by this feature (see Performance).
- The admin and the account settings must not change behaviour or addresses.
- Routes must not depend on the database: `route:cache` (the maintenance page's "Rebuild caches") has to keep
  working, and switching a language on must not need a cache rebuild.

## Decided by the user

- Built-in catalogue, not hand-typed codes.
- Default language without a prefix; other languages `/<code>/…`.
- Languages that are off are unavailable to the site.
- `/login`, `/settings/*`, `/admin` and other service addresses carry no language prefix.
- Guest on a service page: the language of the site they last visited if the interface has a translation for
  it, otherwise the default. After signing in, the language of the profile, as today.
- Foundation only, shown working on the home page (`/` and `/en`).

## Approaches

### Routing

1. **Each public route registered twice, with a static prefix pattern** — picked. A `Route::localized()`
   group registers its routes once without a prefix and once under `{locale}`, where the pattern is the
   alternation of every catalogue code. The catalogue is code, not data, so the routes are cacheable and never
   change when settings do. A middleware decides per request: the default language's own prefix redirects, a
   language that is off or not added is a 404. Catalogue codes are reserved slugs, so a first segment equal to
   a code is always a language and never content.
2. **Strip the prefix before routing** (rewrite the request path in an early middleware, register each route
   once). Fewer routes, but the request no longer matches its real address: `url()->current()`, signed URLs,
   Livewire and redirects all need special handling. Rejected.
3. **A package** (`mcamara/laravel-localization`). A dependency for a small amount of code, with its own
   config and conventions, and it reads its languages from config at route registration. Rejected.

### Storage of the site's languages

1. **Site settings** — picked. Two settings applied over config like every other one, read from the cache
   that already exists: zero queries per request. Content rows will later store the language code as a string,
   so no foreign key is needed.
2. **A `languages` table.** Natural if languages carried much data of their own or content needed a foreign
   key; neither is true, and it would add a query (or a second cache) to every public request. Rejected.

## Design (the builder's calls unless listed under "Decided by the user")

### Catalogue

`config/languages.php`: code → `['name' => English name, 'native' => native name, 'rtl' => bool]`. A fixed
list shipped with the kit: the ISO 639-1 languages plus a few common regional or script variants (`pt-br`,
`zh-hans`, `zh-hant`, `en-gb`, `sr-latn`; the builder may adjust this short list). Codes are lowercase, letters
and hyphens, and are what appears in the address. No `intl` extension is required to read it.

`App\Support\Localization\Language` (value object): `code`, `name`, `native`, `rtl`, `hreflang()` (BCP 47
casing from the code: `pt-br` → `pt-BR`, `zh-hans` → `zh-Hans`), `direction()` (`ltr`/`rtl`),
`hasInterface()` (the code is a key of `app.available_locales`). An interface language's folder and key must
equal the catalogue code (`lang/pt-br`, not Laravel's `pt_BR`), so the two sets link without a mapping; the
spec says so (builder's call).

### Settings

| Setting | Config | Meaning |
|---|---|---|
| `content_locales` | `content.locales` | ordered list of `['code' => string, 'active' => bool]`: the languages added to the site |
| `content_default_locale` | `content.default_locale` | the default language's code |

New `config/content.php`. With nothing stored, the defaults are derived, not written: the default language is
the site's default interface language, and the list is that one language, active. An existing site therefore
keeps working with one language and no prefix anywhere.

`Application::setLocale()` writes the request's language into `config('app.locale')`, so after `SetLocale`
runs that key no longer holds the site default (the trap of decision 48). The site's default interface
language and the derived content defaults are therefore captured when the settings are applied in
`AppServiceProvider::boot()`, before any middleware, into config keys nothing else writes
(`content.default_locale`, `content.locales`, `content.interface_default`); `ContentLocales` and `SetLocale`
read those, never `app.locale`.

Invariants, kept by the actions and re-checked when the config is read (a broken stored value falls back to
the derived default instead of breaking the site): every code is in the catalogue and appears once; the
default is in the list and active; the list is never empty.

`App\Support\Localization\ContentLocales` (singleton, reads config only):

- `all()`: the added languages, the default first, then in the order added.
- `active()`: those that are on.
- `default()`, `isActive(string $code)`, `find(string $code)`.
- `current()`: the content language of this request (the default outside public routes), `setCurrent()`.
- `route(string $name, array $parameters = [], ?string $locale = null): string`: the address of a localized
  route in a language (the current one when null): the plain route for the default, `localized.{name}` with
  the code otherwise.
- `alternates(): array<code, url>`: the current public route in every active language (for a language
  switcher and `hreflang`), empty outside a localized route.

### Addresses and routing

- `Route::localized(Closure $routes)` (a macro, registered in a provider): registers the group's routes as
  they are, and again under the prefix `{locale}` with names prefixed `localized.` and the pattern built from
  the catalogue codes. Both copies carry the `SetContentLocale` middleware.
- `App\Http\Middleware\SetContentLocale`:
  - no `{locale}` parameter: the content language is the default;
  - `{locale}` is the default language: **301** to the same address without the prefix, query string kept,
    sent with `Cache-Control: no-cache` (builder's call): a browser must not keep it forever, because after
    the default changes that prefix becomes a real address;
  - `{locale}` is active: that language;
  - `{locale}` is off or not added: **404**;
  - then: `ContentLocales::setCurrent()`, `app()->setLocale($code)` (so `__()`, dates and `<html lang>`
    follow the content language) and the translator's fallback is set to the site's default interface
    language for this request, so a string with no translation in the content language shows in the site's
    default language, not in `APP_FALLBACK_LOCALE` (the user was told untranslated strings show in the
    default language); the
    `{locale}` parameter is removed from the route so controllers and pages never receive it, and the code is
    written to the session key `site_locale` **only when it differs** from the stored one.
- The home page moves into `Route::localized()`: `/` and `/en` show it. Decision 10 stands (the demo landing
  page's text is not translated); it gets `<html lang dir>`, the `hreflang` alternates and a minimal language
  switcher, so the foundation can be seen and tested in a browser.
- Not localized, as today: `admin/*`, `settings/*`, `dashboard`, Fortify's routes, `media/*`, `storage/*`,
  Livewire's and Flux's own routes.
- Livewire components on public pages: Livewire's update requests go to an unprefixed address. Livewire
  itself restores only `app()->getLocale()` (`SupportLocales`), not `ContentLocales::current()` or the
  translator fallback, so `SetContentLocale` is registered with `Livewire::addPersistentMiddleware()`:
  Livewire re-matches the page's original path and runs it again with `{locale}`. On such a request the
  middleware must not redirect and must not write the session; a language switched off meanwhile still
  answers 404. Read from the vendor code, not run: the builder proves it with a real component on a
  localized route and says plainly if it does not hold. A public page must not flip language after the first
  interaction.

### Service pages for guests

`SetLocale` (web group) picks the interface language in this order:

1. the signed-in user's `locale`, if it is an interface language (as today);
2. the session's `site_locale`, if it is an interface language **and** still an active content language;
3. the default interface language (`app.locale`, as today).

On localized routes `SetContentLocale` runs after it and wins, signed in or not: the site is shown in the
language of its address.

### The default language and the interface default

The user wants one notion: "the default language; the site and the admin are in it by default". The existing
setting `default_locale` (General tab, "Default language", applied to `app.locale`) is the interface default
and can only be an interface language, while the default content language can be any catalogue language. So:

- The General tab's field moves to the Languages tab and is no longer a separate choice in the usual case.
- Choosing a default content language that has an interface translation also stores it as `default_locale`.
- Choosing one that has none leaves `default_locale` as it is, and the tab shows one extra field,
  "Language of the admin and sign-in pages for guests", listing the interface languages. It is shown only in
  this case.

This is the builder's reading of the user's sentence, to be confirmed when the document is reviewed. The
builder check pointed out its cost: "Make default" would silently change the language of the admin and
sign-in pages for everyone without a profile language, and a Ukrainian site with an English admin for guests
would be impossible. The alternative put to the user: the "Make default" confirmation carries a checkbox
"Also use it for the admin and sign-in pages" (ticked by default, shown only when the interface has that
language), and the field is always visible on the tab.

### Languages tab

Site settings, a seventh tab `languages` after General, permission `settings.edit` as for the page.

```
Site settings:  General | Languages | Access | Email | Logo | Colors | Media

 Site languages                                                    [ + Add language ]
 The default language opens without a prefix; the others at /<code>/…

 Українська   Ukrainian   uk   /            [Default] [Interface]              ⋮
 English      English     en   /en/…        [Interface]            (●) on      ⋮
 Deutsch      German      de   /de/…                               ( ○) off    ⋮
 Polski       Polish      pl   /pl/…                               ( ○) off    ⋮
```

- Each row: native name, English name, code, how its address looks, badges ("Default", "Interface" when the
  admin is translated into it), a switch, and the "⋮" menu (decision 35): **Make default**, **Remove**.
- **Add language**: a dialog with a search field over the catalogue (filtering in the browser by native name,
  English name and code; languages already added are left out; no Flux Pro component). A new language is
  added **off**.
- The switch turns a language on or off at once. Turning one off asks first ("its pages will answer 404").
  The default language has no switch.
- **Make default**: only for an active language. The confirmation states the consequence: the old default
  moves under its prefix and the new one loses it, so every public address changes; links from outside and
  search engines will meet a redirect or a 404.
- **Remove**: not for the default; asks first. In this stage a language has no content, so nothing else is
  lost. When content exists, removal will have to account for it; that rule belongs to the content stage.
- Every change is applied at once through an action (no Save button on this tab, like the Logo tab), each
  with an actor and `settings.edit`: add, switch, make default, remove. Success and refusals are toasts.
- The screen must look finished: a card, proportional columns, readable on a phone (the client sees it).

### Reserved slugs

`App\Support\Localization\ReservedSlugs::contains(string $slug): bool` (case-insensitive), for the content
types that come later. Reserved are:

- every catalogue code (not only the added ones: a language may be switched on tomorrow);
- the first path segment of every registered route that is not localized (computed from the router, so a new
  admin or vendor route is covered without editing a list);
- a short fixed list of names the web server answers itself (`storage`, `build`, `index.php`, `favicon.ico`,
  `robots.txt`, …) in `config/content.php`.

Nothing calls it on a public request; it is for saving content.

### Head and themes

- A partial for public layouts prints `<link rel="alternate" hreflang>` for every active language of the
  current page plus `x-default` pointing at the default language.
- `<html lang>` uses the language's `hreflang()` and `dir` its direction on localized pages.

### Performance

Per public request the feature adds: config reads (the settings are already loaded as a whole from the
cache on every request) and a string comparison with the session value; the session value changes only when
the visitor changes language and rides on the session write Laravel already makes. No query and no cookie of
its own. (With the kit's default `database` cache and session stores, those existing reads and writes are
queries; the feature adds none to them.) Each public route exists twice in the route table, which
costs nothing with or without the route cache. The reserved-slug computation runs only when content is saved.

### Tests

- Catalogue: codes well-formed and unique, every interface language is in it, `hreflang()` casing.
- Settings: derived defaults with nothing stored; broken stored values fall back; invariants.
- Actions: add (unknown code, duplicate), switch (the default refused), make default (inactive refused,
  interface default follows only when the interface has that language), remove (the default refused);
  permissions.
- Routing: `/` and `/en`; the default's prefix redirects 301 with its query string; off and not-added codes
  404; a switched-on language works without rebuilding anything; with `route:cache` built; the `{locale}`
  parameter does not reach the page; non-localized routes unchanged.
- Service pages: guest after `/en` sees `/login` in English; after a language without an interface
  translation sees the default; after that language is switched off sees the default; a signed-in user keeps
  their own language in the admin and sees the site in the language of its address.
- Helpers: `route()`, `alternates()`, the `hreflang` partial.
- Reserved slugs: catalogue codes, `admin`, `login`, `media`, a route added in the test.
- The tab: the list, add with search results, the confirmations, the conditional interface-language field,
  both translations.

### Docs

A new spec `docs/specs/languages.md` linked from CLAUDE.md; `docs/specs/site-settings.md` (the tab, the moved
field, the new settings); a CLAUDE.md decision; translations in `lang/en` and `lang/uk`.

## Left to the builder

The exact catalogue entries, class and action names, the layout details of the tab, the session key's name,
the wording of the texts.

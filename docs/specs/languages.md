# Content languages

The languages of the site's content and how a language shows in an address. Pages are the first content type built on
it (`docs/specs/content.md`); posts and themes come later. Design:
`docs/superpowers/specs/2026-11-14-content-languages-design.md`. Decision 56 in CLAUDE.md.

## Two sets of languages

| | Interface languages | Content languages |
|---|---|---|
| What | the admin, account settings, sign-in pages | the public site's content |
| Where from | `config('hata.admin.interface_locales')`, each with Hata's `resources/lang/<code>/` or the site's `lang/vendor/hata/<code>/` | the catalogue `config/internal/languages.php`, chosen in Site settings → Languages |
| Who chooses | each user, in their account settings; the site has a default | an administrator with `settings.edit` |

They are independent: a site may have twelve content languages and two interface languages. Adding a content
language needs no deploy and no translation of the admin. The one link: a content language whose code is also
an interface language shows the service pages in that language (below). For that link, an interface
language's folder and key must equal the catalogue code (`lang/vendor/hata/pt-br`, not Laravel's `pt_BR`).

## Catalogue

`config/internal/languages.php`: code → `name` (English), `native`, optional `rtl`. About 130 languages (ISO 639-1 plus
`pt-br`, `zh-hans`, `zh-hant`, `en-gb`, `sr-latn`). Codes are lowercase with hyphens and are what appears in
the address. Editing the file changes the route pattern, so the route cache must be rebuilt after it.

- `Hata\Support\Localization\LanguageCatalog`: `all()`, `find()`, `has()`, `codes()`, `pattern()` (the route
  pattern: an alternation of every code).
- `Hata\Support\Localization\Language` (value object): `code`, `name`, `native`, `rtl`, `hreflang()`
  (`pt-br` → `pt-BR`, `zh-hans` → `zh-Hans`), `direction()`, `hasInterface()`.

## The site's languages

Two site settings, applied over config like the others (`docs/specs/site-settings.md`):

| Setting | Config | Meaning |
|---|---|---|
| `content_locales` | `hata.content.locales` | list of `['code' => string, 'active' => bool]`, in the order added |
| `content_default_locale` | `hata.content.default_locale` | the default language |

`Hata\Support\Localization\ContentLocales` (singleton) reads config only:

- `entries()`: the list made valid, the default first: codes outside the catalogue and repeats are dropped; a
  default that is not in the list is replaced (the default interface language if listed, else the first
  entry); an empty list is the default alone; the default is always on. A broken stored value therefore never
  breaks the site.
- With nothing stored the site has one language, the default interface language, and no prefix anywhere.
- `all()`, `active()`, `default()`, `defaultCode()`, `has()`, `isActive()`, `language($code)`.
- `codeForPrefix($prefix)`: the language of a public address from its `{locale}` parameter (the default without one);
  `isDefaultPrefix($prefix)`: the address carries the default language's own prefix (answered with a 301).
- `interfaceDefault()`: the site's default interface language, from `hata.content.interface_default`.
  `SiteSettings::apply()` writes that key (the stored `default_locale`, else `app.locale` as the config files
  give it, read when the singleton is built). **Never read `app.locale` for the site default**:
  `Application::setLocale()` writes the current request's language into it.
- `current()`: the content language of this request (the default outside public pages).
- `route($name, $parameters, $locale = null)`: the address of a localized route in a language.
- `alternates()`: the current public page in every active language (code → address); empty elsewhere.
- `useAlternates($alternates)`: a page whose address differs per language (a content record) hands over its own
  alternates (code → address); `alternates()` then returns exactly these. `setCurrent()` clears them, so they never
  reach the next request (the singleton lives as long as the container).

## Addresses

- The default language has no prefix: `/about`. Every other active language: `/<code>/about`.
- `/<default code>/…` answers **301** to the address without the prefix (query string kept), with
  `Cache-Control: no-cache`: after the default changes, that prefix is a real address, so a browser must not
  keep the redirect for good.
- A language that is off, or not added to the site: **404**. Switching a language on works at once.
- Not localized: `admin/*`, `settings/*`, `dashboard`, Fortify's routes, `media/*`, `storage/*`, Livewire's
  routes.

### Routing

`Route::localized(function () { … })` (a macro registered in `HataServiceProvider::register()`) registers its
routes twice: under `{locale}` with names prefixed `localized.` and the catalogue pattern, and then as they
are. The prefixed copy comes first on purpose: a wildcard route of the default language (`{slug}/{child}`)
would otherwise take `/de/about` for itself.
Both copies carry `Hata\Http\Middleware\SetContentLocale`. The pattern comes from the catalogue file, not from
settings, so the routes can be cached and never change when a language is switched. Catalogue codes are
reserved slugs, so a first segment equal to a code is always a language.

`SetContentLocale`, per request: decides the language (above; through `ContentLocales::codeForPrefix()` and
`isDefaultPrefix()`, which `LookUpContent` uses too, so the content route's sessionless lookup reads an address the
same way), removes `{locale}` from the route's parameters
(pages never receive it), sets `ContentLocales::current()` and `app()->setLocale()`, points the translator's
fallback at the site's default interface language (a string the content language lacks shows in that
language, not in `APP_FALLBACK_LOCALE`), and remembers the language in the session (`site_locale`) when it
differs from the stored one.

It is a Livewire **persistent middleware**: Livewire's update requests go to an unprefixed address and restore
only `app()->getLocale()`, so Livewire re-runs it against the page's own address. On such a request (told by
the real request's route being Livewire's update route, not by a header a visitor could send) it does not redirect and does not write the session; a language switched off meanwhile still
answers 404.

Public pages today:

- The home page (`home` / `localized.home`). It prints `<html lang dir>`, the `hreflang` alternates
  (`partials/hreflang.blade.php`: one per active language plus `x-default`, nothing with a single language) and a
  minimal language switcher; its text is still Laravel's demo page (decision 10).
- Content records (`content` / `localized.content`, `{path}` matching lowercase slugs joined by `/`, registered as fallbacks inside
  `Route::localized()` so every explicit route wins; `docs/specs/content.md`, Public side). `LookUpContent` runs on
  these two routes before the session starts: a language that is not added answers its 404 there, without a
  session, and so does one that is off unless the request carries a session or "remember me" cookie (an editor may
  preview a content record in it; `SetContentLocale` decides after the session, see the content spec), and for a request without a session cookie an address with no record or with hidden content too; the default language's prefix still goes to
  `SetContentLocale` for its 301. They hand their public
  translations to `useAlternates()`, so `hreflang` lists only those, with `x-default` only when the default language has
  one, and the switcher (`partials/site/language-switcher.blade.php`) links a language without a translation to that
  language's home page.

## Service pages

`SetLocale` (web group) picks the interface language:

1. the signed-in user's `locale`, if it is an interface language;
2. the session's `site_locale` (the language of the site the visitor last saw), if it is an interface
   language and still an active content language;
3. `ContentLocales::interfaceDefault()`.

On public pages `SetContentLocale` runs after it, so the site is in the language of its address for guests
and signed-in users alike.

## Languages tab

Site settings → Languages (`?tab=languages`, `settings.edit`). Partials
`partials/admin/settings/languages.blade.php` and `languages-modals.blade.php`.

- **List**: native name, English name, the address pattern, badges "Default" and "Interface" (the admin is
  translated into it), a switch, and the "⋮" menu with **Make default** (active languages only) and
  **Remove**. The default language has neither switch nor menu.
- **Add language**: a dialog listing the catalogue languages not on the site, filtered in the browser by
  native name, English name or code. A language is added **off**.
- **Switch**: on at once; off after a confirmation (its pages will answer 404).
- **Make default**: a confirmation that states that every public address changes. When the admin is
  translated into the language, it carries a checkbox "Use it for the admin and sign-in pages too" (ticked):
  the stored `default_locale` follows only when it is ticked.
- **Remove**: after a confirmation. A language that has content records (those in the trash count too) is
  refused with a danger toast that says how many (`site_settings.languages.errors.has_content`): delete them
  for good first, or switch the language off to hide it.
- Every change is stored at once and reloads the tab with a toast; a refusal is a danger toast. An unsaved
  choice in the field below is dropped by that reload (so the leave-page warning does not fire).
- **Language of the admin and sign-in pages**: the field `default_locale` (moved here from the General tab),
  one of the interface languages, saved with the tab's Save button.

Actions (`Hata\Actions\Settings`, each `handle(AdminUser $actor, …)`, authorizing `settings.edit`; refusals throw
`Hata\Exceptions\ContentLocaleException`): `AddContentLocale` (unknown, already added),
`SetContentLocaleActive` (not added, the default), `SetDefaultContentLocale` (not added, off;
`$alsoInterface`), `RemoveContentLocale` (not added, the default, has records: `Entry::withTrashed()` counted by `locale`,
`ContentLocaleException::hasContent($count)`). They always store the whole list with its
default, so the first change also stores what was derived until then.

## Reserved slugs

`Hata\Support\Localization\ReservedSlugs::contains($slug)` (case-insensitive), for the content types to check
when a slug is saved: every catalogue code (not only the added ones), the first segment of every route that
is not a localized public one (computed from the router; a first segment that is a parameter, as in the content
route, is skipped), and `hata.content.reserved_slugs` (what the web server
answers itself: `storage`, `build`, `robots.txt`, …). Nothing calls it on a public request.

## Performance

A public request adds config reads and one comparison with the session value; the session value changes only
when the visitor changes language and rides on the session write Laravel already makes. No query and no
cookie of its own. Each public route exists twice in the route table.

## Not included

Content models and their translations; theme strings and their admin-editable overrides; translated slugs; a
language per domain; choosing a language from `Accept-Language`; reordering languages; previewing a language
that is off.

## Tests

`tests/Feature/Localization/ContentLanguagesTest.php`: the catalogue; derived and broken stored values; the
four actions and their permission; addresses (prefix, 404s, the 301 and its cache header, switching on, a
changed default, parameters and address helpers, `hreflang`, independence from settings); a real Livewire
component on a localized route through Livewire's update endpoint; service pages; reserved slugs; the tab.

## Known limitations

- Building the route cache with these routes is checked by serializing them, not by running `route:cache`.
- The tab's add dialog (search in the browser) is covered only by a browser check.
- Changing the default language changes every public address; old addresses of the old default are not
  redirected.
- Signing out starts a new session, so the remembered site language is forgotten until the visitor opens a
  public page again.
- Two administrators changing the list at the same moment: the later write wins, the earlier change can be
  lost.
- The redirect from the default language's prefix is decided before the language of the session is updated,
  so it never changes the remembered language.

## Stored at install

`HataSeeder` (so `hata:install` and `db:seed`) stores the content-language list once, while none is stored: the interface default of the moment as the only language, active and default (`content_locales`, `content_default_locale`, through `SiteSettings::put()`). It never overwrites a stored list. Without it the default content language would follow the interface default, so changing "Default language" in Site settings would move the site's content language. The fallback in `ContentLocales` stays for sites with nothing stored.

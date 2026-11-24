# Part 3: head tags, sitemap, home page

2026-11-24. The third part of the content stage (`2026-11-14-content-types-design.md`, "Public side"), designed
again now that Hata is a Composer package. Themes come right after it, so the head is built to be shared by any
layout a theme brings.

## Intent

A client site built on Hata must look finished to search engines and to people who share a link: every public
page carries its description, its canonical address and an Open Graph card with a picture; search engines find
every public page in every language through `sitemap.xml`; and the site's `/` shows a page the client chose,
not Laravel's demo welcome page.

## Goals

1. Head tags on every public page: description, canonical, Open Graph (and the Twitter card), next to the title
   and `hreflang` that exist today.
2. Site-wide fallbacks, in Site settings: a description per content language and one Open Graph image.
3. `/sitemap.xml` with every public address of every active language, and `/robots.txt` pointing to it.
4. A home page setting: a page chosen in Site settings is shown at `/` (and `/<code>/` in other languages).
5. A neutral built-in home page in place of Laravel's welcome page, used until a page is chosen and in a
   language where the chosen page is not public.

## Non-goals

- Themes, a theme registry, theme CSS (the next stage). The neutral home page lives in today's `layouts/site`.
- Structured data (JSON-LD), per-record `noindex`, per-record Open Graph image separate from the record image.
- Splitting the sitemap past 50,000 addresses (one file; noted in the spec as a known limit).
- An image sitemap, `changefreq`/`priority` (ignored by Google).
- A robots.txt editor in the admin.

## Anti-goals

- No extra cost on a public page view beyond what is stated under "Cost" (the user's standing rule: performance
  first, no extra load from features).
- No head tag that differs between a theme and the built-in layout: a theme gets the whole head by one directive,
  never by copying markup.
- `robots.txt` never names the admin path (`HATA_ADMIN_PATH` may be set to hide it).
- The installer never deletes a `robots.txt` the site has changed.

## Approaches (chosen with the user)

**Where a layout gets its head tags.**
- **A, chosen:** a request-scoped `PageMeta` service that the controller fills (title, description, canonical,
  image, type, preview/noindex) and a Blade directive `@hataHead` that prints the whole head block from it. It
  follows today's `ContentLocales::useAlternates()` + `partials/hreflang` pattern; a theme writes one line, and a
  module (a shop) fills the same service from its own controller.
- B, rejected: view variables (`$pageTitle`, `$meta`, ...). Every layout and theme must know all of them, and
  each page type would pass them its own way.

**How the sitemap is produced.**
- **A, chosen:** built on request, streamed from cursors, with `Cache-Control: public, max-age=3600` and
  `Last-Modified`; a request with `If-Modified-Since` gets 304 after one cheap query. No new storage.
- B, rejected: a prebuilt file in the cache, rebuilt after each save. Every path that changes visibility
  (translation, trash, status, a type or language switched off, the home page) would have to invalidate it, for
  a file fetched a few times a day.

**`Sitemap:` in robots.txt.**
- **A, chosen:** Hata serves `/robots.txt` from a route with the absolute sitemap address, and `hata:install`
  deletes Laravel's stock `public/robots.txt` only when its content is exactly the stock one; a changed file is
  left alone and the line to add is printed.
- B, rejected: leave robots.txt alone; the sitemap would have to be submitted by hand in Search Console for
  every site.

Both routes can be switched off in `config/hata.php` for a site that has its own.

## Design

### 1. `PageMeta` and `@hataHead`

- `Hata\Support\Seo\PageMeta`, bound `scoped` (fresh per request, also under Octane and in queue jobs). Holds:
  `title` (the whole `<title>`), `description` (plain text or null), `canonical` (absolute URL or null), `image`
  (a `Media` or null), `type` (`website` | `article`), `noindex` (bool). Setters return `$this`; a getter for
  each. The description setter normalises: strips tags, decodes entities, collapses whitespace, cuts to 160
  characters on a word boundary with "…" when cut.
- `@hataHead` (registered with the other asset directives) renders `hata::partials.site.head`, which prints in
  this order: `<title>`, `meta description`, `meta robots noindex` (when set), `link rel=canonical`, the
  Open Graph and Twitter tags, then the existing `hreflang` partial. `layouts/site` and the new home page use
  it instead of their own `<title>`/robots/hreflang lines. `$pageTitle`/`$noindex` view variables go away (the
  site views fill `PageMeta` through the controller).
- Fallbacks are applied by `PageMeta` when the head renders, not by every caller: no description → the site
  description of the current content language; no image → the site Open Graph image.
- Open Graph and Twitter: `og:site_name` (`app.name`), `og:title` (the page's own title: a record's title or SEO
  title, without the site name), `og:description`, `og:url` (= canonical), `og:type`, `og:locale` (the language's
  hreflang with `_`, e.g. `uk` or `en_US` as the catalogue gives it), `og:locale:alternate` for every other
  alternate in `hreflang`, `og:image` (absolute URL of the optimised raster, with `?v=`), `og:image:width`,
  `og:image:height`, `og:image:alt` (the file's alt), `twitter:card` = `summary_large_image` with an image,
  `summary` without. Nothing is printed for an empty value.
- An SVG, a document or a missing file never becomes `og:image` (social networks don't read SVG): the record's
  image is skipped for the site image; the site image picker refuses SVG.
- A preview (draft, scheduled, switched-off language) sets `noindex` and prints no canonical and no Open Graph.

What each public page puts in:

| Page | title | description | canonical | image | type |
|---|---|---|---|---|---|
| Record (`site.entry`) | `documentTitle()` | `seo_description`, else `excerpt`, else the start of the body | `$entry->url()` | record image | `article` (pages: `website`) |
| Term (`site.term`) | `documentTitle()` | same rule as a record | term URL, `?page=N` kept for N > 1 | none → site image | `website` |
| Type list (`site.list`) | plural label - site name | site description | list URL, `?page=N` kept for N > 1 | site image | `website` |
| Home with a chosen page | the page's `documentTitle()` | as a record | the language's home URL | record image | `website` |
| Neutral home | site name | site description | the language's home URL | site image | `website` |

The canonical is built from the record or route, never from the request URL, so `/about/`, `/about?utm=…` and
`/about` all point to `/about`.

### 2. Site settings: the "Site" section

No new tab: the fields go into the **Site** section built by `2026-11-24-settings-sections-design.md` (site name,
timezone), as cards "Home page" and "Search and sharing" / "Пошук і соцмережі", all saved by the section's Save.

- **Home page.** Choice of a page (the `pages` type) in the default content language, shown as title and path,
  plus "Built-in home page" (no choice). Stored on Save as the setting `home_page` = the chosen record's id
  (config `hata.content.home_page`, int or null). The home is that record and whatever translations it has at
  the moment, read through its current `entry_group_id`, never a stored group id: linking or unlinking
  translations (decision 63) changes no setting, and the home follows the chosen page (the user's choice, from
  the builder check). The picker's form is the builder's call; it must stay usable
  with a few hundred pages (a search, not a plain select of everything).
- **Site description**, one textarea per active content language (label: the language's native name), a
  character counter with 160 as the recommended length, max 300. Stored on Save as one setting
  `site_description` = `{code: text}` (config `hata.seo.description`); a blank field removes that language's
  entry; values of languages that are off are kept.
- **Image for sharing.** The media picker as on the Logo tab (image only; PNG, JPG, JPEG, WebP; no SVG, no GIF),
  stored on Save, setting `og_image` = media id (config `hata.seo.image`), usage recorded against
  the `Setting` row (label "Image for sharing", link to the Site section). Deleting the file clears the setting inside
  the delete's transaction, like a logo (`Setting::forgetMedia()` learns the new field). A hint under it:
  "1200 × 630 px is shown best"; when the chosen file is smaller than 600 × 315, a warning.
- `UpdateSiteSettings` (Save of the section), with a `SetShareImage` / `RemoveShareImage` pair (as `SetLogo` /
  `RemoveLogo`) called by it, all behind `settings.edit` (+ `media.view` for picking, as for logos). The home page must be a
  live page of the pages type; any other id is a field error.

### 3. The home page

- Route `home` (`/` inside `Route::localized()`) moves from `Route::view` to `Hata\Http\Controllers\HomeController`.
- With `home_page` set and a **public** record in the current language in the chosen record's current group
  (one query: `entries` where `entry_group_id` = the chosen record's, by subquery, and `locale` = current; pages
  type, not trashed),
  the controller renders it exactly as `ContentController` renders a record (same view `hata::site.entry`, same
  data: body, galleries, terms, alternates; the shared part is extracted, not copied), except that the canonical
  and the record's alternates point to each language's home address, not to the page's own path.
- Otherwise it renders the neutral home page `hata::site.home` in `layouts/site`: the site name as the heading
  and the site description of the language under it. A user with `settings.edit` also sees a hint strip
  "Choose a home page in Site settings" with a link to `?tab=site` (when a page is chosen but not public in this
  language, the hint says that instead). The strip is the same component as the preview strip.
- The chosen page's own address (`/about`, `/de/über-uns`) answers 301 to that language's home address, with
  `no-cache` (the choice can change). Only the record itself: pages under it keep their addresses. A preview of
  the page (draft in that language) is not redirected, so editors can still open it.
- Alternates of the home: each active language's home URL (as today), so the switcher and `hreflang` are
  unchanged.
- Deleting the chosen record for good clears `home_page` (in the same transaction, as a logo is cleared).
  Trashing, unpublishing, moving it to another language, linking it to another group or unlinking it leaves the
  setting: `/` shows the chosen record's current translations and falls back to the neutral page for languages
  where none is public. Example: "Про нас" (uk, chosen) is unlinked from "About" (en): `/` stays "Про нас" and
  `/en/` becomes the neutral page.
- The admin marks the home page: a "Home page" badge on its row in the content list and in its edit form header.
- `resources/views/welcome.blade.php` is removed; decision 10 is superseded. A site that overrode
  `vendor/hata/welcome.blade.php` loses it: a breaking change, released as `v0.3.0`.

### 4. `/sitemap.xml`

- Route `GET sitemap.xml` (name `sitemap`), outside `Route::localized()`, without the `web` middleware group
  (no session, cookies or locale; like `media.show`). Registered only when `hata.seo.sitemap` is true (default
  true, in the published `config/hata.php`).
- Content, all absolute URLs, active languages and active types only:
  - every language's home address;
  - every public record (not the chosen home page's records; their address is the home);
  - every type list (a type whose base in that language is not empty);
  - every term that has at least one public record of an active type.
- Each `<url>` has `<lastmod>` (`updated_at` of the record; for a list, the latest `published_at`/`updated_at`
  among its public records; for a home, the chosen record's or the latest of the site) and `xhtml:link
  rel="alternate"` for its public translations (records read ordered by `entry_group_id`, `locale`, so a group's
  translations arrive together; lists and homes use the active languages).
- Streamed (`response()->stream`) from `lazyById`/cursor reads with only the columns needed (no bodies).
- Headers: `Content-Type: application/xml; charset=utf-8`, `Cache-Control: public, max-age=3600`,
  `Last-Modified` = the latest of `entries.updated_at` (trashed included: a trash sets `updated_at`),
  `entries.published_at` not in the future (a scheduled record goes live without writing a row) and
  `settings.updated_at`; still one query. A matching `If-Modified-Since` gets 304 without building the body.
  (Builder check; my call.)
- A record whose path changes because its parent moved must get a new `updated_at` (the descendants' path update
  in `EntryPaths` sets it), so `lastmod` follows a move.

### 5. `/robots.txt`

- Route `GET robots.txt` (name `robots`), without the `web` group, registered when `hata.seo.robots` is true
  (default true). Body:
  ```
  User-agent: *
  Disallow:

  Sitemap: https://example.com/sitemap.xml
  ```
  The `Sitemap:` line only when the sitemap route is on. `Cache-Control: public, max-age=86400`.
- The web server serves `public/robots.txt` first when the file exists, so `hata:install` (a new `HostFiles`
  step) deletes it when its content, trimmed, is exactly Laravel's stock `User-agent: *\nDisallow:`; any other
  content stays, and the step reports the line to add by hand. Sites installed before this release: the release
  notes and `docs/specs/package.md` say to delete the stock file.

### Cost

- A record, term or list page: no new query when the page has its own image; one query (by primary key,
  memoised per request) for the site image otherwise. The bound in `PublicContentTest` moves from 5 to 6.
- The neutral home: 0 queries today, at most 1 (the site image) after; with a session, the hint needs no query
  beyond the user already loaded.
- The home with a chosen page: the record by group and locale (one indexed query) plus what a record page costs.
- The chosen page's own address: no new query (the redirect is decided from the record already found).
- `/sitemap.xml`: one `Last-Modified` query; on a miss, a few cursor reads over `entries` and one for the terms
  with public records. `/robots.txt`: none.

## Specs and decisions to update (with the code)

- `docs/specs/content.md`: head tags, home page, sitemap, robots, cost per view.
- `docs/specs/site-settings.md`: the Site section's new cards, settings `home_page`, `site_description`, `og_image`.
- `docs/specs/package.md`: `@hataHead` for themes and modules (`PageMeta`), the `seo.*` config, robots.txt
  installer step and the by-hand step for existing sites, `welcome` removed.
- `docs/specs/media.md`: the new usage label.
- CLAUDE.md: new decisions (head via `PageMeta`, sitemap on request, robots route, home page and neutral home;
  decision 10 superseded).

## Left to the builder

- The home page picker's exact form (search field, dropdown, or the translation picker's pattern).
- Class and partial names beyond `PageMeta`, `@hataHead`, `HomeController`, `hata::site.home`.
- How the shared record rendering is extracted from `ContentController`.
- The exact Ukrainian wording (both languages are required for every new key).
- Test layout, as long as it covers: every tag per page kind and their fallbacks, preview without canonical/OG,
  the home with and without a chosen page and per language, the 301 from the page's own address, clearing the
  setting on delete for good, sitemap content (inactive languages/types, drafts, trash, terms without records,
  the home), 304, both routes off by config, the installer step on stock / changed / missing `robots.txt`, query
  counts.

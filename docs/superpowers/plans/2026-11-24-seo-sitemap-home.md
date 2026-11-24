# Part 3: head tags, sitemap, home page — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Every public page carries description, canonical and an Open Graph card; `/sitemap.xml` and `/robots.txt` exist; `/` shows a page chosen in Site settings or a neutral built-in home instead of Laravel's welcome page.

**Architecture:** A request-scoped `Hata\Support\Seo\PageMeta` is filled by the public controllers and printed by one directive `@hataHead` (partial `hata::partials.site.head`), so a theme or module gets the whole head with one line. The home page is a `HomeController` that renders the chosen record's translation in the current language through a rendering class extracted from `ContentController`, or `hata::site.home`. The sitemap is streamed on request from cursors with `Last-Modified`/304; robots.txt is a route. The new settings live in the existing **Site** settings section.

**Tech Stack:** Laravel 13, Livewire 4 SFC + Flux free 2.21, Pest 5 on Testbench, Tailwind 4 (vite-plus, `dist/` committed).

**Spec:** `docs/superpowers/specs/2026-11-24-seo-sitemap-home-design.md` (read it in full first). Settings sections it builds on: `docs/superpowers/specs/2026-11-24-settings-sections-design.md`, CLAUDE.md decision 75.

## Global Constraints

- Work on a branch `part-3-seo-home` from `main` (00c233d). Merge and push only on the user's word; release tag **`v0.3.0`** (the spec says `v0.2.0`, which is already taken by the settings sections; removing `welcome` is breaking, so 0.x → minor).
- Public pages stay plain Blade: no Livewire, Flux or admin JavaScript (decision 59).
- Cost (spec, "Cost"): a record/term/list page adds **no query** when the page has its own shareable image or no site image is set, **one** (by primary key, memoised per request) for the site image otherwise; the `PublicContentTest` bound "at most five more than the home page" becomes **six**; "one query for a plain page on a one-language site" stays **1**. The chosen page's own address: no new query. `/robots.txt`: no query. `/sitemap.xml`: one `Last-Modified` query; 304 builds no body.
- `robots.txt` never names the admin path. The installer never deletes a changed `public/robots.txt`.
- Every new translation key goes into `resources/lang/en` **and** `resources/lang/uk` in the same task (`TranslationParityTest`).
- Description normalisation: strip tags, decode entities, collapse whitespace, cut to **160** characters on a word boundary with `…` when cut. Site description field: recommended **160**, max **300**.
- Share image extensions: `png`, `jpg`, `jpeg`, `webp` (no SVG, no GIF). Hint "1200 × 630 px is shown best"; warning under **600 × 315**.
- Sitemap headers: `Content-Type: application/xml; charset=utf-8`, `Cache-Control: public, max-age=3600`. Robots: `Cache-Control: public, max-age=86400`. Redirect from the home page's own address: **301** with `Cache-Control: no-cache`.
- Both new routes run `->withoutMiddleware('web')` (no session, cookies, CSRF, locale), like `media.show`.
- New config (published `config/hata.php`): `content.home_page` (null), `seo.description` ([]), `seo.image` (null), `seo.sitemap` (`env('HATA_SITEMAP', true)`), `seo.robots` (`env('HATA_ROBOTS', true)`).
- New settings (`SiteSettings::define` in `HataServiceProvider`, next to the others): `home_page` → `hata.content.home_page` (`int`), `site_description` → `hata.seo.description` (`array`), `og_image` → `hata.seo.image` (`int`).
- Run `vendor/bin/pint --dirty --format agent` before every commit; `composer test` (Pint, PHPStan level 7, Pest) green at the end of each task. Never `pest --parallel`.
- UI parts (the Site section cards, the badges, the neutral home's look) are shown to the user in the demo before the branch is called done (memory: UI first, then tests; polished client-facing UI: cards, proportional fields).

## Review Focus

1. **A home page that is only partly public.** Chosen record public in `uk`, its `en` translation a draft: `/` shows the record, `/en/` the neutral page with the "not public in this language" hint for an editor and no hint for a guest; `/en/<draft path>` still opens as a preview for an editor (not redirected). Test in Task 4.
2. **Canonical built from the request.** `/about/`, `/about?utm_source=x` must print `<link rel="canonical" href="http://localhost/about">`; a term list `?page=1` drops the page, `?page=2` keeps it, any other query parameter is dropped. Test in Task 2.
3. **An unusable image becoming `og:image`.** A record image that is an SVG, a document, or a deleted media row → the site image (or no `og:image`); `twitter:card` falls back to `summary`. Test in Task 2.
4. **Sitemap leaking hidden addresses.** Drafts, scheduled records, trashed records, records of a switched-off language or type, terms whose only records are drafts or of a switched-off type, and the home page's own record address never appear. Test in Task 6.
5. **Deleting the chosen home page through any path.** Delete for good, bulk delete, empty trash and the daily prune all go through `DeleteEntry::purge()` and must clear `home_page` in the same transaction. Test in Task 4 (purge via `PruneEntryTrash` and via `DeleteEntry`).

---

### Task 1: `PageMeta`, the new config and setting keys

**Files:**
- Create: `src/Support/Seo/PageMeta.php`
- Modify: `config/hata.php` (add `content.home_page`, new top-level `seo` block, each with the file's usual comment header), `src/HataServiceProvider.php` (`scoped(PageMeta::class)` next to `HataVite`; the three `define()` calls)
- Test: `tests/Unit/Seo/PageMetaTest.php` (Unit suite, no DB) and `tests/Feature/Seo/PageMetaFallbackTest.php`

**Interfaces:**
- Produces (`Hata\Support\Seo\PageMeta`, all setters return `static`):
  - `public const array IMAGE_EXTENSIONS = ['png', 'jpg', 'jpeg', 'webp'];`
  - `setTitle(string $title)` / `title(): string` — falls back to `config('app.name')`
  - `setOgTitle(?string $title)` / `ogTitle(): string` — falls back to `title()`
  - `setDescription(?string $text)` (stores the normalised value) / `description(): ?string` — falls back to `config('hata.seo.description')[current content language code]`, normalised
  - `setCanonical(?string $url)` / `canonical(): ?string` — null while `isNoindex()`
  - `setImage(?Media $media)` / `image(): ?Media` — own image when `isShareable()`, else the site image (`config('hata.seo.image')`, one `Media::find`, memoised on the instance; only when shareable), else null
  - `setType(string $type)` (`'website'|'article'`) / `type(): string` — default `website`
  - `setNoindex(bool $noindex = true)` / `isNoindex(): bool`
  - `public static function normalizeDescription(?string $text): ?string`
  - `public static function isShareable(Media $media): bool` — `(new MediaAcceptance('image', self::IMAGE_EXTENSIONS))->allows($media)`

- [ ] **Step 1: Write the failing unit tests** (`tests/Unit/Seo/PageMetaTest.php`)

```php
it('normalises a description', function (?string $in, ?string $out) {
    expect(PageMeta::normalizeDescription($in))->toBe($out);
})->with([
    'tags and entities' => ['<p>Fish &amp; <b>chips</b></p>', 'Fish & chips'],
    'whitespace' => ["  a\n\n  b\t c ", 'a b c'],
    'blank' => ['  <p> </p> ', null],
    'null' => [null, null],
]);

it('cuts a long description on a word boundary with an ellipsis', function () {
    $text = str_repeat('слово ', 40); // 240 chars
    $cut = PageMeta::normalizeDescription($text);

    expect(mb_strlen($cut))->toBeLessThanOrEqual(160)
        ->and($cut)->toEndWith('…')
        ->and(mb_substr($cut, 0, -1))->not->toEndWith(' ')
        ->and(str_starts_with($text, mb_substr($cut, 0, -1)))->toBeTrue();
});

it('keeps a description of exactly 160 characters whole', function () {
    expect(PageMeta::normalizeDescription(str_repeat('a', 160)))->toBe(str_repeat('a', 160));
});
```

- [ ] **Step 2: Write the failing feature tests** (`tests/Feature/Seo/PageMetaFallbackTest.php`): `title()` falls back to the site name; `description()` falls back to `hata.seo.description.<current code>` and returns null for a language without one; `canonical()` is null after `setNoindex()`; `image()` returns the own raster image, skips an SVG (`Media::factory()` SVG state) and a document for the site image, returns null when `hata.seo.image` points at a deleted row; the site image is read with one query however often `image()` is called (`DB::enableQueryLog`); `app(PageMeta::class)` is a fresh instance after `app()->forgetScopedInstances()`.

- [ ] **Step 3: Run them to verify they fail**

Run: `vendor/bin/pest tests/Unit/Seo tests/Feature/Seo`
Expected: FAIL, class `Hata\Support\Seo\PageMeta` not found.

- [ ] **Step 4: Implement `PageMeta`, the config keys, the scoped binding and the three setting definitions.** The cut: take the first 159 characters, drop back to the last space, trim, append `…`; when there is no space in them, cut at 159 characters.

- [ ] **Step 5: Run the tests and `composer test`**

Run: `vendor/bin/pest tests/Unit/Seo tests/Feature/Seo && composer test`
Expected: PASS (`PublishedConfigTest` may need the new keys listed if it pins the config's shape).

- [ ] **Step 6: Commit** — `git commit -m "Add PageMeta and the SEO and home page settings"`

---

### Task 2: `@hataHead` and the head of record, term and list pages

**Files:**
- Create: `resources/views/partials/site/head.blade.php`, `src/Support/Content/EntryPage.php`
- Modify: `src/HataServiceProvider.php` (directive next to `hataAssets`), `resources/views/layouts/site.blade.php`, `resources/views/site/{entry,term,list}.blade.php`, `src/Http/Controllers/ContentController.php`, `resources/boost/guidelines/core.blade.php` (themes: use `@hataHead`, fill `PageMeta`)
- Test: `tests/Feature/Seo/HeadTagsTest.php`; modify `tests/Feature/Content/PublicContentTest.php` (bound 5 → 6)

**Interfaces:**
- Consumes: `PageMeta` (Task 1).
- Produces:
  - Blade directive `@hataHead` → `<?php echo view('hata::partials.site.head')->render(); ?>`
  - `Hata\Support\Content\EntryPage` (constructor-injected `ContentTypes`, `ContentLocales`, `BodyRenderer`, `PageMeta`):
    `public function response(Entry $entry, ?AdminUser $viewer, bool $preview, bool $languageOff, ?string $canonical = null): Response` — fills `PageMeta` (below), sets the alternates unless the caller already did (`?array $alternates` is not needed: `ContentController` calls `ContentLocales::useAlternates()` before, `HomeController` leaves the default), and returns the `site.entry`/`site.term` view with the data `ContentController` passes today; `no-store` on a preview. `termsOf()`, `galleries()`, `filedUnder()` and `page()` move here from `ContentController` (`page()` stays public for `listOf()`, or `listOf()` moves too — implementer's call, one copy only).
  - The views no longer receive or pass `$pageTitle`/`$noindex`; `layouts/site` no longer reads them.

What `EntryPage` puts into `PageMeta` (spec table): title `documentTitle()`; ogTitle `seo_title ?: title`; description `seo_description ?: excerpt ?: body`; canonical `$canonical ?? $entry->url()`, plus `?page=N` for a term when `N > 1` (from the paginator's current page); image the record image (terms: none); type `website` for a taxonomy or a `hierarchical()` type, else `article`; noindex `$preview`. `ContentController::listOf()`: title `pluralLabel().' - '.config('app.name')`, ogTitle `pluralLabel()`, no description (site fallback), canonical the list URL (with `?page=N` for `N > 1`), no image (site fallback).

The partial prints, in order, nothing for an empty value: `<title>`, `meta name="description"`, `meta name="robots" content="noindex"`, `link rel="canonical"`, and — only when not noindex — `og:site_name` (`app.name`), `og:title`, `og:description`, `og:url` (= canonical), `og:type`, `og:locale` (current language `hreflang()` with `-` → `_`), one `og:locale:alternate` per other code in `ContentLocales::alternates()`, `og:image` (absolute: `url($media->url())`), `og:image:width`, `og:image:height`, `og:image:alt` (`altFor(current locale)`), `twitter:card` (`summary_large_image` with an image, else `summary`); then `@include('hata::partials.hreflang')`. `layouts/site` replaces its `<title>`, robots and hreflang lines with `@hataHead`.

- [ ] **Step 1: Write the failing tests** (`HeadTagsTest.php`, helpers from `PublicContentTest` reused or copied into the file): one test per page kind asserting each tag with exact values, e.g. for a published page `about` with `seo_description` "About us" and a WebP image:

```php
$this->get('/about')->assertOk()
    ->assertSee('<meta name="description" content="About us">', escape: false)
    ->assertSee('<link rel="canonical" href="http://localhost/about">', escape: false)
    ->assertSee('<meta property="og:type" content="website">', escape: false)
    ->assertSee('<meta property="og:image" content="http://localhost/', escape: false)
    ->assertSee('<meta name="twitter:card" content="summary_large_image">', escape: false);
```

Plus: description falls back excerpt → body → site description of the language; a post is `article`; the list and a term use the site image; `og:locale` and `og:locale:alternate` on a two-language page; a draft preview has `noindex` and no `rel="canonical"` and no `og:`; **Review Focus 2** (`/about/`, `/about?utm_source=x`, term `?page=1` vs `?page=2&utm=x` → canonical `…?page=2`); **Review Focus 3** (SVG record image → site image; no site image → no `og:image` and `twitter:card` `summary`); a view that uses `@hataHead` outside `layouts/site` prints the title (render a one-line Blade string with `Blade::render('@hataHead')`).

- [ ] **Step 2: Change the cost test** to `toBeLessThanOrEqual(6)` and add: with `hata.seo.image` set and the page without an image, the page costs one query more than the same page with its own image.

- [ ] **Step 3: Run to verify failure**

Run: `vendor/bin/pest tests/Feature/Seo/HeadTagsTest.php`
Expected: FAIL (no canonical/OG tags in the output).

- [ ] **Step 4: Implement** the partial, the directive, `EntryPage`, the controller and view changes, the guideline line.

- [ ] **Step 5: Run** `vendor/bin/pest tests/Feature/Seo tests/Feature/Content && composer test` — Expected: PASS (`PublicContentTest` unchanged apart from the bound, including "one query for a plain page on a one-language site" = 1).

- [ ] **Step 6: Commit** — `git commit -m "Print description, canonical and Open Graph tags on public pages through @hataHead"`

---

### Task 3: The home page: `HomePage`, `HomeController`, the neutral home

**Files:**
- Create: `src/Support/Content/HomePage.php`, `src/Http/Controllers/HomeController.php`, `resources/views/site/home.blade.php`, `resources/views/partials/site/home-hint.blade.php`
- Modify: `src/HataServiceProvider.php` (`scoped(HomePage::class)` next to `PageMeta`), `routes/web.php` (`Route::get('/', HomeController::class)->name('home')` inside `Route::localized()`), `resources/views/partials/site/preview-strip.blade.php` (extract its amber strip markup into a component or partial both strips use — "the same component as the preview strip"), `resources/lang/{en,uk}/content.php` (keys under `content.public.home.*`)
- Delete: `resources/views/welcome.blade.php`
- Modify tests: `tests/Feature/Package/ViewNamespacesTest.php` (`welcome` → `site.home`), `tests/Feature/ExampleTest.php` if it asserts welcome content
- Test: `tests/Feature/Content/HomePageTest.php`

**Interfaces:**
- Consumes: `EntryPage::response()` (Task 2), `PageMeta`.
- Produces (`Hata\Support\Content\HomePage`, bound `scoped`):
  - `id(): ?int` — `config('hata.content.home_page')` when an int
  - `recordFor(string $locale): ?Entry` — the record of the pages type, not trashed, `locale = $locale`, `entry_group_id = (select entry_group_id from entries where id = :id)` (one query, subquery; the record's `image` and `groupEntries` eager-loaded as `ContentRouter` loads them for a record page); memoised per locale; any state (the caller checks `isPublic()`)
  - `includes(Entry $entry): bool` — whether the record is the chosen one or in its group: `$entry->id === id()`, else, when `groupEntries` is loaded, `groupEntries->contains('id', id())` (no query), else a comparison with the chosen record's group id (one query, memoised)
  - `groupId(): ?int` — the chosen record's current group id (one query, memoised; withTrashed)

`HomeController::__invoke(Request $request)`: `ContentLocales::useAlternates()` is not called (every active language's home, as today). With `recordFor(current)` public → `EntryPage::response($record, $user, preview: false, languageOff: false, canonical: ContentLocales::route('home'))`. Otherwise `site.home` with `PageMeta`: title and ogTitle `config('app.name')`, canonical the language's home URL, no description/image (fallbacks); the hint for a user with `settings.edit`: "Choose a home page in Site settings" (link `route('admin.settings.edit', ['tab' => 'site'])`) when `id()` is null, "The home page is not published in this language" when a record exists but is not public or there is none in this language.

`site/home.blade.php`: `@extends('hata::layouts.site')`, heading = site name, under it the site description of the language (`PageMeta::description()`), the hint in `@section('strip')`.

- [ ] **Step 1: Write the failing tests** (`HomePageTest.php`): no setting → 200, `<h1>` with the site name, the language's description, canonical `http://localhost` (check `ContentLocales::route('home')`'s exact form in a one-off assertion), no hint for a guest, the "choose" hint for a user with `settings.edit`; chosen published `uk` page → `/` shows its title and body with canonical the home URL, `og:type` `website`; its `en` translation published → `/en/` shows it; **Review Focus 1** (en a draft → `/en/` neutral, editor hint "not published", guest no hint); after `UnlinkTranslation` of the `en` record `/` still shows the `uk` one and `/en/` is neutral (spec example); the chosen record trashed → neutral page everywhere; a record of another type id in the setting → neutral; query count: neutral home without site image adds 0 queries to the old home's count (compare against a baseline of a route with the same middleware, or assert `publicQueriesOf(..., ['entries', 'media'])` is 0), chosen home adds one `entries` query plus what a record page costs.

- [ ] **Step 2: Run to verify failure** — `vendor/bin/pest tests/Feature/Content/HomePageTest.php` — Expected: FAIL (welcome view rendered).

- [ ] **Step 3: Implement** `HomePage`, `HomeController`, the views, the strip extraction, the translations; delete `welcome.blade.php`; update the two tests that name `welcome`.

- [ ] **Step 4: Rebuild the site CSS** — `npm run build` (new classes in `site/home`), commit `dist/` with the task.

- [ ] **Step 5: Run** `vendor/bin/pest tests/Feature/Content tests/Feature/Package tests/Feature/ExampleTest.php && composer test` — Expected: PASS.

- [ ] **Step 6: Commit** — `git commit -m "Show the chosen page or a neutral home page at /"`

---

### Task 4: Redirect from the home page's own address, clearing on delete, admin badges

**Files:**
- Modify: `src/Http/Controllers/ContentController.php`, `src/Actions/Content/DeleteEntry.php` (`purge()`), `resources/views/livewire/admin/content/index.blade.php` (row badge next to the title), `resources/views/partials/admin/content/entry-form.blade.php` (badge by the `<flux:heading>` at line ~78), `src/Livewire/Admin/EntryListPage.php` (expose the home group id once per render), `resources/lang/{en,uk}/content.php` (`content.list.home_page`)
- Test: add to `tests/Feature/Content/HomePageTest.php`; `tests/Feature/Admin/Content/EntryIndexTest.php`, `EntryFormTest.php`

**Interfaces:**
- Consumes: `HomePage::includes()`, `HomePage::groupId()`, `HomePage::id()` (Task 3).

Rules: in `ContentController`, after the match is a record, not a preview and the language active, `HomePage::includes($entry)` → `redirect(ContentLocales::route('home', [], $entry->locale), 301)->header('Cache-Control', 'no-cache')`. Pages under it are untouched. In `purge()`, after `$ids` is known and before the delete: when `HomePage::id()` is in `$ids`, `app(SiteSettings::class)->put(['home_page' => null])` (inside the same transaction; the settings refresh after commit as for logos). Badge: `<flux:badge size="sm" color="sky" icon="home">` "Home page" / "Головна сторінка" on rows and in the form header of every record whose `entry_group_id === groupId()` and type is `pages` (no query per row: `groupId()` once).

- [ ] **Step 1: Write the failing tests:** `/about` (chosen) → 301 to `http://localhost` with `no-cache`; `/de/ueber-uns` (its translation) → 301 to `http://localhost/de`; `/about/team` → 200; an editor opening the draft `en` translation's address gets the preview (200, `noindex`), not a redirect; the redirect costs no query more than the page did (`publicQueriesOf`); **Review Focus 5**: the chosen record purged by `DeleteEntry` and, separately, by `PruneEntryTrash` (trashed 31 days ago) → `config('hata.content.home_page')` null and the `settings` row gone; purging another record leaves it; badge shown on the chosen row and its translation's row, absent on others, and in the form header (`assertSeeHtml` with a `data-test="home-page-badge"`).

- [ ] **Step 2: Run to verify failure.** — Expected: FAIL (200 instead of 301; setting still set).

- [ ] **Step 3: Implement.**

- [ ] **Step 4: Run** `vendor/bin/pest tests/Feature/Content tests/Feature/Admin/Content && composer test` — Expected: PASS.

- [ ] **Step 5: Commit** — `git commit -m "Redirect the home page's own address and clear the setting when it is deleted"`

---

### Task 5: The Site section: home page, site description, image for sharing

**Files:**
- Create: `src/Actions/Settings/SetShareImage.php`, `src/Actions/Settings/RemoveShareImage.php`
- Modify: `resources/views/livewire/admin/settings/sections/site.blade.php`, `src/Actions/Settings/UpdateSiteSettings.php`, `src/Models/Setting.php` (usage label/URL/forget for `og_image`), `resources/lang/{en,uk}/site_settings.php` (`site_settings.home.*`, `site_settings.seo.*`)
- Test: `tests/Feature/Admin/Settings/SiteSectionTest.php` (extend), `tests/Feature/Actions/Settings/ShareImageTest.php`

**Interfaces:**
- Consumes: `PageMeta::IMAGE_EXTENSIONS`, `PageMeta::isShareable()` (Task 1); the setting keys of Task 1.
- Produces:
  - `SetShareImage::handle(AdminUser $actor, int $mediaId): void` — as `SetLogo::handle()`: `settings.edit`; `viewAny Media` unless the id is the current one; `MediaSelection(accept: 'image', multiple: false, extensions: PageMeta::IMAGE_EXTENSIONS)`; then in a transaction `put(['og_image' => $id])` and `MediaUsage::sync(<og_image Setting row>, 'og_image', [$id])`
  - `RemoveShareImage::handle(AdminUser $actor): void` — as `RemoveLogo`
  - `UpdateSiteSettings::handle()` learns: when `home_page` is in `$data` and not null, the id must be a non-trashed `pages` record, else `ProtectedActionException::because('hata::site_settings.home.unavailable')`

The section: `fields()` = `['site_name', 'timezone', 'home_page', 'site_description', 'og_image']`. Rules: `values.home_page` `['nullable', 'integer', 'min:1']`, `values.site_description` `['array']`, `values.site_description.*` `['nullable', 'string', 'max:300']`, `values.og_image` `['nullable', 'integer', 'min:1']`. `load()`: `site_description` = every active content language code → its stored text or `''`; `og_image` = the chosen file's id when the row exists, else null. `prepare()`: `home_page`/`og_image` to int or null; `site_description` = the stored array with the active languages' entries replaced, blank ones removed (off languages kept), `null` when empty. `persist()` (one `DB::transaction`, like Appearance): `og_image` through `SetShareImage`/`RemoveShareImage` (a refused file → `values.og_image` error `hata::media.picker.invalid`), the rest through `UpdateSiteSettings` (a `ProtectedActionException` → `values.home_page` error with its message).

Cards (follow Appearance's card markup; the user wants polished cards):
- **Home page**: two radio options "Built-in home page" / "A page"; for "A page" the chosen page as title + path with a "Change" button, and a search input (`wire:model.live.debounce.300ms="homeSearch"`, a public property outside `$values`, so it never counts as a change) over a computed `homeCandidates()`: up to 20 live `pages` records in the default content language, `search($homeSearch)`, ordered by path, columns `id, title, path, locale, type` only. The chosen record is read with one query (computed `homeRecord`), and when it is no longer live the card says so.
- **Search and sharing** / «Пошук і соцмережі»: one textarea per active content language labelled with its native name, an Alpine character counter "N / 160" turning amber over 160; the share image picker (`<livewire:hata::media-picker accept="image" :extensions="PageMeta::IMAGE_EXTENSIONS">`) with a preview, the hint "1200 × 630 px is shown best", and a warning when the chosen file's `width < 600 || height < 315`.

- [ ] **Step 1: Write the failing action tests** (`ShareImageTest.php`): set stores the id and a `media_usages` row for the `og_image` setting; an SVG and a PDF are refused (`ValidationException` on `media`); without `media.view` only the current id passes; remove clears both; deleting the file through the media library's delete action clears `og_image` (`Setting::forgetMedia`); the media details show the label "Image for sharing" with a link to `?tab=site`.

- [ ] **Step 2: Write the failing section tests** (`SiteSectionTest.php`, Livewire `test()` on `hata::admin.settings.sections.site`): saving a chosen page stores `home_page`; a trashed page or a post id → error on `values.home_page`, nothing stored; choosing "Built-in" stores null; `homeCandidates` finds "Про нас" by `про` and leaves out other languages, other types and trashed records; descriptions save as `{"uk": "…"}`, a blanked language is removed, an inactive language's stored value survives a save, 301 characters is an error; saving only the site name stores no `site_description`, `home_page` or `og_image` row (changed-only Save); a failing share image rolls back a changed site name (all or nothing); typing in the search does not mark the form dirty (`saved` unchanged, Save stores nothing).

- [ ] **Step 3: Run to verify failure.** — `vendor/bin/pest tests/Feature/Admin/Settings/SiteSectionTest.php tests/Feature/Actions/Settings/ShareImageTest.php` — Expected: FAIL.

- [ ] **Step 4: Implement** the actions, the `Setting` and `UpdateSiteSettings` changes, the section's PHP and markup, both languages' strings.

- [ ] **Step 5: Run** the two files and `composer test` (`TranslationParityTest` included) — Expected: PASS.

- [ ] **Step 6: Commit** — `git commit -m "Choose the home page, site descriptions and the share image in the Site section"`

---

### Task 6: `/sitemap.xml`

**Files:**
- Create: `src/Support/Seo/Sitemap.php`, `src/Http/Controllers/SitemapController.php`
- Modify: `routes/web.php` (when `config('hata.seo.sitemap')`: `Route::get('sitemap.xml', SitemapController::class)->withoutMiddleware('web')->name('sitemap')`, outside `Route::localized()`), `src/Support/Content/EntryPaths.php` (`move()`: the descendants' `UPDATE` also sets `updated_at = ?` to `now()`)
- Test: `tests/Feature/Seo/SitemapTest.php`, `tests/Feature/Content/EntryPathsTest.php` (one test), `tests/AdminPath` or a Feature test that boots with `hata.seo.sitemap = false` (follow how `AdminPathValidationTest`/`HostRoutesTest` reload routes with another config)

**Interfaces:**
- Consumes: `HomePage::groupId()`/`id()`/`recordFor()` (Task 3), `ContentLocales`, `ContentTypes`.
- Produces (`Hata\Support\Seo\Sitemap`):
  - `lastModified(): ?CarbonImmutable` — one query: the greatest of `max(entries.updated_at)` (trashed included), `max(entries.published_at)` where `<= now()`, `max(settings.updated_at)` (subselects in one `select`)
  - `lines(): Generator<int, string>` — the XML, chunk by chunk

Content (absolute URLs; `htmlspecialchars(…, ENT_XML1)`; `<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" xmlns:xhtml="http://www.w3.org/1999/xhtml">`; `lastmod` as `toAtomString()` in UTC):
1. per active language, its home (`ContentLocales::route('home', [], $code)`), lastmod = the chosen record's public translation's `updated_at`, else the latest public `updated_at` of that language; `xhtml:link` for every active language's home.
2. records: active types that are not taxonomies, active languages, `public()`, excluding the pages records of `HomePage::groupId()`; columns `id, entry_group_id, type, locale, path, updated_at`; `orderBy('entry_group_id')->orderBy('locale')->cursor()`; rows of one group are buffered and emitted together, each with `xhtml:link rel="alternate" hreflang="<hreflang()>"` for every row of the group (itself included) when the group has more than one. `Entry::url()` builds the address with no query (the type's base is code).
3. type lists: one grouped query `select type, locale, max(updated_at), max(published_at)` over public records of active types and languages; a list is listed when the type's base in that language is non-empty and it has a public record; lastmod = the later of the two maxima.
4. terms: active taxonomy types, active languages, not trashed, `whereExists` a public record of an active type in `entry_terms`; same group-buffered cursor and alternates as records.

Controller: build a `Response` with `setLastModified()`, `setPublic()`, `setMaxAge(3600)`; `isNotModified($request)` → return it (304, no body built); else `response()->stream(fn () => print each line, 200, headers + Content-Type)`, copying `Last-Modified` and `Cache-Control`.

- [ ] **Step 1: Write the failing tests** (`SitemapTest.php`; read the body with `$response->streamedContent()`): headers exact; the home of each active language and `xhtml:link` between them; a published page with a `de` translation gives two `<url>` with mutual alternates; **Review Focus 4** (draft, scheduled, trashed, `fr` switched off, posts type switched off, a tag with only a draft post, a tag with only posts of a switched-off type — none appear); the chosen home record's own path absent and the home's lastmod is its `updated_at`; `/posts` list listed with lastmod = newest post, absent with no public posts; a term with a public post listed; `If-Modified-Since` equal to `Last-Modified` → 304 with empty body and at most one query; a scheduled record whose time has come moves `Last-Modified` forward without a write; no session cookie on the response (`assertCookieMissing(config('session.cookie'))`); `hata.seo.sitemap = false` → 404 (route absent).

- [ ] **Step 2: Write the failing `EntryPathsTest` case:** moving a page sets its child's `updated_at` to now (`$this->travel(1)->hour()` before the move; compare).

- [ ] **Step 3: Run to verify failure.** — `vendor/bin/pest tests/Feature/Seo/SitemapTest.php tests/Feature/Content/EntryPathsTest.php` — Expected: FAIL (404 / old `updated_at`).

- [ ] **Step 4: Implement** `Sitemap`, the controller, the route, the `EntryPaths` change.

- [ ] **Step 5: Run the files, then the suite on MySQL too** (the `UPDATE` and the aggregate query are driver-sensitive): `composer test`, then the MySQL run as CI does (see `.github/workflows/tests.yml` for the env variables; never at the same time as the SQLite run). Expected: PASS on both.

- [ ] **Step 6: Commit** — `git commit -m "Serve sitemap.xml built on request with Last-Modified"`

---

### Task 7: `/robots.txt` and the installer step

**Files:**
- Create: `src/Http/Controllers/RobotsController.php`
- Modify: `routes/web.php` (when `config('hata.seo.robots')`: `Route::get('robots.txt', RobotsController::class)->withoutMiddleware('web')->name('robots')`), `src/Support/Install/HostPaths.php` (`robots` = `public/robots.txt`, in `under()` and `forApplication()`), `src/Support/Install/HostFiles.php`, `src/Console/Commands/InstallCommand.php` (`editHostFiles()`), `tests/fixtures/host/public/robots.txt` (Laravel's stock file, if the fixture lacks it)
- Test: `tests/Feature/Seo/RobotsTest.php`, `tests/Feature/Install/HostFilesTest.php`, `tests/Feature/Install/InstallCommandTest.php`

**Interfaces:**
- Produces: `HostFiles::removeStockRobots(string $robots): HostFileResult` — missing file → `alreadyDone()`; content with `\r\n` → `\n`, trimmed, `=== "User-agent: *\nDisallow:"` → delete, `changed()`; anything else → `unrecognized($manual)` with the manual line `Sitemap: <APP_URL>/sitemap.xml` (`url('sitemap.xml')`; a stub is not needed for one line). `InstallCommand` reports it as "robots.txt (public/robots.txt)"; when `hata.seo.robots` is false the step is skipped.

Body, exactly (no `Sitemap:` line when the sitemap route is off):

```
User-agent: *
Disallow:

Sitemap: http://localhost/sitemap.xml
```

`Content-Type: text/plain; charset=utf-8`, `Cache-Control: public, max-age=86400`.

- [ ] **Step 1: Write the failing tests:** the body and headers above; without the sitemap no `Sitemap:` line; the body never contains `config('hata.admin.path')` (set `HATA_ADMIN_PATH`-style custom path in the test config); no session cookie; robots off → 404. `HostFilesTest`: stock file (also with CRLF and a trailing newline) deleted → `Changed`; changed file kept → `Unrecognized` with the `Sitemap:` line; missing → `AlreadyDone`. `InstallCommandTest`: the report line for each case.

- [ ] **Step 2: Run to verify failure.** — Expected: FAIL.

- [ ] **Step 3: Implement.**

- [ ] **Step 4: Run** `vendor/bin/pest tests/Feature/Seo/RobotsTest.php tests/Feature/Install && composer test` — Expected: PASS.

- [ ] **Step 5: Commit** — `git commit -m "Serve robots.txt with the sitemap address and remove the stock file on install"`

---

### Task 8: Browser pass with the user (checkpoint)

Not for a subagent: the controller does it with the user.

- [ ] **Step 1:** `npm run build` if any view changed since Task 3; `composer test` green; commit `dist/`.
- [ ] **Step 2:** Point the demo at the branch as for the settings sections: in `/Users/vitalii/Herd/hata-demo` back up `composer.json`/`composer.lock` to the session scratchpad, require `sliusardev/hata:dev-part-3-seo-home as 0.3.0` through a path repository (symlink). Ask the user to run `! php artisan migrate` there only if a migration exists (none is planned).
- [ ] **Step 3:** Ask the user to check at https://hata.test: Site settings → Site (both new cards, the search with many pages, the counters, the small-image warning, Save), `/` neutral and with a chosen page in each language, the 301 from the page's address, the badges in the content list and form, view-source of a page (head tags), `/sitemap.xml`, `/robots.txt`. Fix what they find (each fix with its test), re-run `composer test`.
- [ ] **Step 4:** Restore the demo's `composer.json`/`composer.lock` from the backup after the release (Task 9 step 5).

---

### Task 9: Specs, decisions, release

**Files:**
- Modify: `docs/specs/content.md` (head tags and `PageMeta`, home page, sitemap, robots, cost per view 5 → 6, the redirect), `docs/specs/site-settings.md` (the Site section's cards; `home_page`, `site_description`, `og_image`), `docs/specs/package.md` (`@hataHead` and `PageMeta` for themes and modules, the `seo.*` config, the robots step and the by-hand step for sites installed before v0.3.0: delete a stock `public/robots.txt`; `welcome` removed, breaking), `docs/specs/media.md` (usage label "Image for sharing"), `CLAUDE.md` (decisions 76–79: head via `PageMeta`/`@hataHead`; sitemap on request with `Last-Modified`; robots route and installer step; home page = chosen record following its translations, neutral home, decision 10 superseded — mark decision 10 *Superseded by decision 79*), `README.md` if it describes the home page or a theme's head
- Fix in the design doc: "released as `v0.2.0`" → `v0.3.0`.

- [ ] **Step 1:** Write the doc changes; `composer test` (docs only, should stay green).
- [ ] **Step 2:** Commit — `git commit -m "Document head tags, sitemap, robots and the home page"`
- [ ] **Step 3:** Whole-branch review (`superpowers:requesting-code-review`), one fix wave, `composer test` on SQLite then MySQL.
- [ ] **Step 4:** On the user's word: fast-forward `main`, push (`git@github.com-sliusardev:sliusardev/hata.git`), delete the branch, tag and push `v0.3.0` with release notes naming the breaking change (`welcome` removed; a site that overrode `vendor/hata/welcome.blade.php` loses it) and the by-hand robots step.
- [ ] **Step 5:** Demo back to Packagist `^0.3` (restore the backup, then `composer require sliusardev/hata:^0.3`); update the progress memory.

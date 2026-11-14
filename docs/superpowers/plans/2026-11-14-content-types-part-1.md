# Content types Part 1: registry, entries with translations, editor, pages

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** A registry of content types and fields, one record per language linked into translation sets, a rich text editor, and the `pages` type working end to end: admin list and form, trash, public addresses with redirects, language switcher and `hreflang`.

**Architecture:** Types and field kinds are PHP classes in `App\Support\Content`, registered by a service provider that also registers their permissions. `entries` holds one row per language, `entry_groups` one row per translation set; a stored `path` makes any page one indexed lookup. Writes go through actor actions (`App\Actions\Content`); the admin is two generic Livewire SFC pages built from the registry; the public side is a plain controller behind one wildcard route tried after all others.

**Tech Stack:** Laravel 13, Livewire 4 SFC, Flux 2.21 free, Alpine, Tiptap (npm, new), `symfony/html-sanitizer` (composer, new), Pest 5.

**Spec:** `docs/superpowers/specs/2026-11-14-content-types-design.md` (approved 2026-11-14), on top of `docs/specs/languages.md` and `docs/specs/media.md`. Mockup of the list and the form: `.superpowers/brainstorm/14575-1794673732/content/admin-content-v5.html` (open it in a browser; it is the look to match). Parts 2 (taxonomies, posts) and 3 (head tags, sitemap, home page) get their own plans after this part is checked by the user.

## Global Constraints

- Branch `content-types-part-1` from `main`. Commit per task on the branch; merging is the user's word. Messages end with `Co-Authored-By: Claude Fable 5.2 <noreply@anthropic.com>`.
- Keys: type `pages` is the stored `entries.type`, the admin address segment and the permission group. Permissions per type: `view`, `create`, `edit`, `delete`. Publishing and restoring need `edit`; trashing and deleting for good need `delete`.
- One record per language; unique (`entry_group_id`, `locale`); a record's language never changes. Copies are independent after creation.
- Public = `status = published` and `published_at <= now()` and not trashed. "Scheduled" is derived, never stored. No job, cron or event publishes anything.
- Slug format `^[a-z0-9]+(-[a-z0-9]+)*$`; `path` at most 255 characters; a top-level slug may not be in `ReservedSlugs` nor a type base of that language.
- Trash keeps a record 30 days (`config('content.trash_days')`); its path stays reserved meanwhile.
- **Performance budgets, asserted in tests:** a public page view ≤ 5 queries beyond what the home page makes today; the admin list's query count does not grow with the number of rows; changing a slug costs a constant number of queries whatever the number of descendants. No Livewire on public pages. The editor's JavaScript is loaded only on pages that show it (dynamic `import()`), so other admin pages do not grow.
- **Look (the admin is shown to clients):** match the mockup; cards with `rounded-xl border`, fields proportional to their content, never a bare full-width form; zinc/accent/sidebar utilities only, so color schemes and dark mode reach every new surface; `<x-admin.empty-state>` for empty lists; `<x-admin.row-actions>` for per-row actions (decision 35); `<x-confirm-modal>` for every destructive action; works at phone width with the right column below the left.
- Flux free only. Every string in `lang/en` and `lang/uk` (parity test). Dates through `->tz(config('admin.timezone'))->isoFormat('LL')`.
- Actions follow decision 25: `handle(User $actor, …)`, `Gate::forUser($actor)->authorize(…)`, one transaction.
- Specs change in the same commit as the code: `docs/specs/content.md` is created in Task 1 and grows with each task.
- Before each commit: `vendor/bin/pint --dirty --format agent`, `composer types:check`, the affected tests; `npm run build` when JS or Tailwind classes change. If `bootstrap/cache/config.php` exists, run `php artisan optimize:clear` before any test run.
- The editor, the picker opened from it and drag-free tag-like inputs run in the browser only. Livewire tests cannot see them: say plainly in the task report what was not exercised.

## Review Focus

1. A tampered form: a language that is not on the site, a parent of another language or type or the record's own descendant, an author who may not create the type, an image id of a document. Each is a field error and nothing is stored (Tasks 5 and 9).
2. A hostile body: `<script>`, `onerror=`, `javascript:` links, `<iframe>`, `style`, a non-numeric `data-media-id`. All gone after save; a missing file's image is dropped at render (Task 4).
3. Two requests creating the same translation: the second opens the existing record, no 500 (Task 5).
4. A slug that collides with the rest of the site: `admin`, `login`, `storage`, `en`, an existing page, a trashed page, an old path kept as a redirect (the redirect row goes, the record wins) (Tasks 3 and 10).
5. Deleting a media file used by a record in the trash, then restoring the record: no dead image, no dead `<img>` (Task 6).

---

### Task 1: Registry of types and fields, the `pages` type, permissions

**Files:**
- Create: `app/Support/Content/ContentTypes.php`, `ContentType.php`, `Types/PagesType.php`, `Fields/Field.php` and `Fields/{Text,Textarea,RichText,Number,Date,Toggle,Select,MediaFile,Gallery}.php`, `app/Providers/ContentServiceProvider.php`, `tests/Fixtures/ProductsType.php` (test-only: base `products`, one field of every kind, `price` shared), `docs/specs/content.md`
- Modify: `config/content.php` (the file exists; add `trash_days` => 30), `bootstrap/providers.php`, `config/permissions.php` (editor role gains `pages.*`), `lang/{en,uk}/permissions.php`, `lang/{en,uk}/content.php` (new), CLAUDE.md (spec link)
- Test: `tests/Feature/Content/ContentRegistryTest.php`

**Interfaces — Produces:**
- `ContentTypes` (singleton): `register(ContentType $type): static`, `all(): array<string, ContentType>`, `find(string $key): ?ContentType`, `get(string $key): ContentType` (throws on unknown), `pattern(): string` (alternation of keys for route constraints).
- `abstract class ContentType`: `key(): string`, `label(): string`, `pluralLabel(): string` (translated), `icon(): string`, `hierarchical(): bool`, `base(string $locale): ?string`, `fields(): list<Field>`, `perPage(): int` (default 20), `permission(string $action): string` (`"{key}.{action}"`), `field(string $name): ?Field`, `sharedFields(): list<Field>`, `localFields(): list<Field>`.
- `abstract class Field`: `static make(string $name): static`, `label(string $key): static`, `shared(): static`, `required(): static`, `rules(): array`, `toStorage(mixed $formValue): mixed`, `toForm(mixed $stored): mixed`, `view(): string` (Blade partial name under `partials.admin.content.fields`), `mediaIds(mixed $stored): list<int>` (empty unless `MediaFile`/`Gallery`), public readonly `name`, `isShared`. `Select::options(array<string,string>)`.
- `PagesType`: key `pages`, hierarchical, `base()` null, no fields.
- `ContentServiceProvider::register()` binds the singleton and registers `PagesType`; `boot()` registers `['view','create','edit','delete']` for every type with `PermissionRegistry`. Tests register `ProductsType` in `beforeEach`.

- [ ] **Step 1: Failing tests**: `pages` is registered and hierarchical with no base; `pattern()` matches `pages` only; an unknown key gives null / throws; after `permissions:sync` the four `pages.*` permissions exist and the `editor` role has them after `RoleSeeder`; every field kind of `ProductsType` round-trips `toStorage`/`toForm` (number as numeric, date as `Y-m-d`, toggle as bool, gallery as a list of ints) and exposes rules; `sharedFields()` is `[price]`; `mediaIds()` of a gallery value `[3, 5]` is `[3, 5]`.
- [ ] **Step 2: Run, expect failures**: `php artisan test --compact tests/Feature/Content/ContentRegistryTest.php`
- [ ] **Step 3: Implement**
- [ ] **Step 4: Pass**, plus `php artisan test --compact tests/Feature/Localization tests/Feature/Authorization`
- [ ] **Step 5: Spec + commit** "Add the content type registry, field kinds and the pages type"

---

### Task 2: Tables and models

**Files:**
- Create: migrations `create_entry_groups_table`, `create_entries_table`, `create_entry_redirects_table`; `app/Models/{EntryGroup,Entry,EntryRedirect}.php`; `app/Enums/EntryStatus.php` (`Draft`, `Published`), `app/Enums/EntryState.php` (`Draft`, `Scheduled`, `Published`); factories for the three models
- Test: `tests/Feature/Models/EntryTest.php`

**Interfaces — Produces:**
- Columns and indexes exactly as the spec's Data section (terms tables belong to Part 2). `entries.image_id` and `author_id` null on delete; `entry_redirects.entry_id` cascades; `entries.parent_id` null on delete.
- `Entry` (SoftDeletes, `HasSearchIndex` from title and slug): casts `fields` array, `status` `EntryStatus`, `published_at` datetime. Relations `group()`, `parent()`, `children()`, `author()`, `image()`, `translations()` (other entries of the group, trashed excluded). Scopes `ofType(string)`, `inLocale(string)`, `public()`. Methods `type(): ContentType`, `state(): EntryState`, `isPublic(): bool`, `depth(): int` (segments of `path` minus one), `url(bool $absolute = true): string` (public address in its language, through `ContentLocales::route()` and the type's base), `fieldValue(string $name): mixed` (from `fields`, or the group's `shared` for a shared field).
- `EntryGroup`: casts `shared` array; `entries()`.
- Factory states: `Entry::factory()->page()`, `->published()`, `->draft()`, `->scheduled()`, `->inLocale('en')`, `->childOf(Entry $parent)`, `->translationOf(Entry $source, string $locale)`. The factory creates the group and sets `path` from the parent.

- [ ] **Step 1: Failing tests**: `public()` returns a published record with a past date and hides a draft, a future date and a trashed one; `state()` gives `Scheduled` for published + future; the unique indexes refuse a second record of a group in one language and a second `pages`/`uk`/`about`; `translations()` lists the other languages; deleting a user nulls `author_id`; `url()` in the site's default language is `/about` and in another active language `/<code>/about`.
- [ ] **Step 2: Run, expect failures**: `php artisan test --compact tests/Feature/Models/EntryTest.php`
- [ ] **Step 3: Implement** (`php artisan make:model … -mf --no-interaction`)
- [ ] **Step 4: Pass**; run the same file against MySQL (scratch database on Herd's MySQL, as earlier parts did)
- [ ] **Step 5: Spec + commit** "Add entries, entry groups and redirects"

---

### Task 3: Slugs, paths and redirects

**Files:**
- Create: `app/Support/Content/EntryPaths.php`, `app/Rules/EntrySlug.php`
- Modify: `lang/{en,uk}/content.php`
- Test: `tests/Feature/Content/EntryPathsTest.php`

**Interfaces — Produces:**
- `EntryPaths::slugFrom(string $title): string` (`Str::slug`; may be empty).
- `EntryPaths::suggest(ContentType $type, string $locale, ?Entry $parent, string $title, ?Entry $ignore = null): string`: the generated slug with `-2`, `-3`… when its path is taken (trashed records and reserved names count as taken).
- `EntryPaths::pathFor(ContentType $type, ?Entry $parent, string $slug): string`.
- `EntryPaths::move(Entry $entry, string $newPath): void`: inside the caller's transaction sets the record's path, rewrites every descendant's path by prefix, stores the old paths in `entry_redirects`, and deletes redirect rows whose path a record now holds. A constant number of queries (bulk insert, one prefix `UPDATE` with the driver's concat, one delete).
- `new EntrySlug(ContentType $type, string $locale, ?Entry $parent, ?Entry $ignore)`: validation rule; messages for format, reserved (top level: `ReservedSlugs::contains()` or a base of any registered type in that language), taken, and a resulting path over 255.

- [ ] **Step 1: Failing tests**: `suggest()` gives `pro-nas`, then `pro-nas-2` when taken, also when the holder is trashed; the rule refuses `About`, `a--b`, `admin`, `login`, `storage`, `en`, `products` (the test type's base), a taken sibling slug, and accepts `admin` as a child slug; `move()` on a page with 50 descendants over three levels rewrites all paths, leaves 51 redirects, and runs the same number of queries as with 2 descendants; moving a record onto a path held by a redirect removes that redirect; a path of 256 characters is refused.
- [ ] **Step 2: Run, expect failures**: `php artisan test --compact tests/Feature/Content/EntryPathsTest.php`
- [ ] **Step 3: Implement**
- [ ] **Step 4: Pass**, on SQLite and MySQL (the prefix update is driver-specific)
- [ ] **Step 5: Spec + commit** "Build entry paths, check slugs and keep old paths as redirects"

---

### Task 4: Body sanitizer and renderer

**Files:**
- Modify: `composer.json` (`composer require symfony/html-sanitizer`, approved by the user)
- Create: `app/Support/Content/BodySanitizer.php`, `app/Support/Content/BodyRenderer.php`
- Test: `tests/Feature/Content/BodyTest.php`

**Interfaces — Produces:**
- `BodySanitizer::clean(?string $html): ?string`: allowlist `p`, `h2`–`h4`, `strong`, `em`, `u`, `s`, `a` (`href` with `http`, `https`, `mailto`, `tel` or a relative address; `target`; `rel`), `ul`, `ol`, `li`, `blockquote`, `br`, `hr`, `code`, `pre`, `img` (`data-media-id` digits only, `alt`; any `src` is removed). Empty result is null.
- `BodySanitizer::mediaIds(?string $html): list<int>`: ids of the image nodes, in order, unique.
- `BodySanitizer::withoutMedia(?string $html, int $mediaId): ?string`: the body without that file's images.
- `BodyRenderer::render(?string $html): HtmlString`: fills each image's `src` from `Media::url()`, adds `width`, `height` when the row has them, `loading="lazy"`, `decoding="async"`; drops images whose row is gone or is not an image; exactly one query when the body has images, none otherwise.

- [ ] **Step 1: Failing tests**: Review Focus 2 as a dataset (each input, the expected output); `mediaIds()`; `withoutMedia()` keeps the other images; `render()` of two images of one file and one of a missing id makes one query, prints the versioned URL twice and no third `<img>`; a body without images makes no query.
- [ ] **Step 2: Run, expect failures**: `php artisan test --compact tests/Feature/Content/BodyTest.php`
- [ ] **Step 3: Implement**
- [ ] **Step 4: Pass**
- [ ] **Step 5: Spec + CLAUDE.md stack table + commit** "Sanitize entry bodies and resolve their images at render"

---

### Task 5: Create, update and translate

**Files:**
- Create: `app/Actions/Content/{CreateEntry,UpdateEntry}.php`, `app/Policies/EntryPolicy.php`, `app/Support/Content/EntryInput.php` (validation rules for the common fields and the type's fields), `app/Support/Content/EntryAuthors.php`
- Modify: `app/Models/Entry.php` and `EntryGroup.php` (`implements UsesMedia`), `lang/{en,uk}/content.php`
- Test: `tests/Feature/Actions/Content/SaveEntryTest.php`

**Interfaces — Consumes:** `EntryPaths`, `EntrySlug`, `BodySanitizer`, `MediaUsage::sync()`, `App\Rules\MediaSelection`.

**Interfaces — Produces:**
- `EntryPolicy`: `viewAny(User, string $type)`, `view`, `create(User, string $type)`, `update`, `delete`, `restore` (= edit), `forceDelete` (= delete), `preview` (= edit); each checks `"{type}.{action}"`.
- `EntryInput::rules(ContentType $type, string $locale, ?Entry $entry): array` and `::validate(...): array`: `title` required max 255; `slug` (`EntrySlug`); `parent_id` (same type and language, not trashed, not the record or its descendant; null for a flat type); `author_id` (in `EntryAuthors`); `image_id` (`MediaSelection`, image); `status`; `published_at` nullable date; `excerpt`, `seo_title` max 255, `seo_description` max 500; `body`; `fields.*` from the type.
- `EntryAuthors::query(ContentType $type): Builder<User>`: users who may create the type (permission holders and super admins); `::options()` returns `id => name`.
- `CreateEntry::handle(User $actor, ContentType $type, string $locale, array $data, ?Entry $source = null): Entry`: authorizes `create`; refuses a language that is not on the site (`ContentLocales::has()`); with `$source` joins its group, else makes a group; when the group already has a record in that language returns that record unchanged (also on a unique-index race); author defaults to the actor; `published_at` becomes now when publishing with none; sanitizes the body; splits field values into `entries.fields` and `entry_groups.shared`; syncs media usages (`image`, `body`, `fields.<name>` on the entry; `shared.<name>` on the group).
- `UpdateEntry::handle(User $actor, Entry $entry, array $data): Entry`: authorizes `update`; the same writes; a changed slug or parent goes through `EntryPaths::move()`.
- `Entry::copyFor(string $locale): array`: the form data of a new translation (title, body, excerpt, per-language fields, image, author, SEO; `parent_id` = the parent's translation in that language or null; slug empty; status draft).
- `Entry::mediaUsageLabel()` ("Page: <title>" by type label), `mediaUsageUrl()` (the edit address when the viewer may view the type), `forgetMedia()` (`image` → null; `body` → `withoutMedia`; `fields.x` → the field emptied or the id taken out of a gallery).

- [ ] **Step 1: Failing tests**: create a page (group made, path, author = actor, body sanitized, usages recorded for the image and a body image); update changes slug and descendants' paths; translate into `en` joins the group, copies, maps the parent to its `en` translation and to null when there is none; a second create for the same group and language returns the first record (Review Focus 3); publishing without a date sets it; a shared field value written from one translation reads from the other; Review Focus 1 as a dataset; without the permission each action is refused; `copyFor()`; `forgetMedia()` for each field kind.
- [ ] **Step 2: Run, expect failures**: `php artisan test --compact tests/Feature/Actions/Content/SaveEntryTest.php`
- [ ] **Step 3: Implement**
- [ ] **Step 4: Pass**
- [ ] **Step 5: Spec + commit** "Create, update and translate entries"

---

### Task 6: Trash, restore, delete, prune; media and language rules

**Files:**
- Create: `app/Actions/Content/{TrashEntry,RestoreEntry,DeleteEntry,PruneEntryTrash}.php`, `app/Console/Commands/PruneContentTrash.php` (`content:prune-trash`)
- Modify: `routes/console.php` (daily), `app/Models/MediaUsage.php` (`forgetAll()` reaches trashed usables), `app/Actions/Settings/RemoveContentLocale.php`, `app/Exceptions/ContentLocaleException.php` (`hasContent(int $count)`), `lang/{en,uk}/*`, `docs/specs/{languages,media}.md`
- Test: `tests/Feature/Actions/Content/EntryTrashTest.php`, additions to `tests/Feature/Localization/ContentLanguagesTest.php` and `tests/Feature/Models/MediaUsageTest.php`

**Interfaces — Produces:**
- `TrashEntry::handle(User $actor, Entry $entry): int`: authorizes `delete`; soft-deletes the record and its descendants with one shared `deleted_at`; returns how many.
- `RestoreEntry::handle(User $actor, Entry $entry): Entry`: authorizes `restore`; restores the record and the descendants trashed at the same instant; a record whose parent is trashed or gone becomes top level (path rebuilt; if that path is taken, the slug gets a suffix).
- `DeleteEntry::handle(User $actor, Entry $entry): void`: authorizes `forceDelete`; only a trashed record; removes it, its descendants in the trash, their media usages, and each group left without records (with its usages).
- `PruneEntryTrash::handle(): int`: deletes for good what was trashed more than `content.trash_days` days ago, in chunks; no actor (decision 16).
- `Entry::descendantsCount(): int` (by path prefix, one query) for the confirmation text.
- `RemoveContentLocale` refuses a language that has entries, trashed ones included, with the count in the message.

- [ ] **Step 1: Failing tests**: trash a page with two levels of children (all trashed, public address 404 once Task 10 exists; here `public()` excludes them, path still reserved for `EntrySlug`); restore brings back exactly those trashed with it and not a child trashed a day earlier; restore after the parent was deleted for good lands at the top level; delete for good removes redirects, usages and an emptied group but keeps a group that still has another language; prune removes a record trashed 31 days ago and keeps one trashed 29 days ago; Review Focus 5 (delete the file while the record is in the trash, restore, `image_id` null and the body without the image); removing a language with one trashed entry is refused with the count.
- [ ] **Step 2: Run, expect failures**
- [ ] **Step 3: Implement**
- [ ] **Step 4: Pass**: `php artisan test --compact tests/Feature/Actions/Content tests/Feature/Localization tests/Feature/Models/MediaUsageTest.php`, then `php artisan test --compact --filter=Media` (shared media code changed)
- [ ] **Step 5: Specs + commit** "Add the entry trash, pruning, and the rules for media and languages"

---

### Task 7: Admin list

**Files:**
- Create: `app/Support/Content/EntryListQuery.php`, `resources/views/pages/admin/content/⚡index.blade.php`, `resources/views/partials/admin/content/translations-grid.blade.php` (the grid of language codes, reused by the form)
- Modify: `routes/admin.php`, `resources/views/layouts/app/sidebar.blade.php` (one item per type the actor may view, before Media, in the Content group; the group shows when any of them or `media.view` holds), `lang/{en,uk}/{content,navigation}.php`
- Test: `tests/Feature/Admin/Content/EntryIndexTest.php`

**Interfaces — Produces:**
- Route `admin/content/{type}` → `admin.content.index`, `{type}` constrained by `ContentTypes::pattern()`; the page authorizes `viewAny`.
- `EntryListQuery::for(ContentType $type, string $locale)`: `->tab('all'|'published'|'drafts'|'scheduled'|'trash')`, `->search(?string)`, `->missingIn(?string $locale)`, `->sort('title'|'date'|'tree', 'asc'|'desc')`, `->paginate(int)`; `counts(): array<string,int>` (one grouped query); results eager-load `image`, `author`, and the group's other entries as `id, entry_group_id, locale, title, status, published_at`. A hierarchical type defaults to `tree` (order by `path`).
- Page state in the query string: `tab`, `q`, `locale`, `missing`, `sort`, `dir`, `page`. The list's language is remembered in the session (`content.list_locale`) and defaults to the site's default language. Language select, "Missing translation in" and the Translations column are absent when the site has one language.
- Row: thumbnail (the image's `thumbUrl()`, a neutral placeholder without one), title linking to the edit page when the actor may open it, the address below it, state badge for draft and scheduled, translations bar with `n/total` (hover opens `translations-grid`), author, date, "⋮" with Edit, View on site (public records), Add translation → the create address, Move to trash. Trash tab: Restore, Delete for good, and "will be removed on <date>".
- Trash confirmation states the number of child pages; delete for good is confirmed. Opening the list calls `PruneEntryTrash` at most once an hour per type (cache key).
- "Create" button → `admin.content.create` with the list's language.

- [ ] **Step 1: Failing tests**: 403 without `pages.view`; the sidebar item shows only with it; tabs filter and their counts are right; search finds Cyrillic titles case-insensitively; the tree order and indent for a three-level tree; `missing=en` lists only records without an `en` translation; the list in `en` shows only `en` records; trash and restore from the row need their permissions and call the actions; the page makes the same number of queries with 5 and with 20 rows; one-language site hides the language controls.
- [ ] **Step 2: Run, expect failures**: `php artisan test --compact tests/Feature/Admin/Content/EntryIndexTest.php`
- [ ] **Step 3: Implement**, matching the mockup's list screen
- [ ] **Step 4: Pass**, `npm run build`
- [ ] **Step 5: Spec + commit** "Add the admin list of entries"

---

### Task 8: Rich text editor and the picker opened from it

**Files:**
- Modify: `package.json` (Tiptap core, starter kit, image; link and underline packages only if the installed major's starter kit lacks them: read the installed version's docs first), `resources/js/app.js`, `resources/views/components/⚡media-picker.blade.php`
- Create: `resources/js/rich-text.js`, `resources/views/components/admin/rich-text.blade.php`
- Test: `tests/Feature/Admin/Media/MediaPickerTest.php` (additions)

**Interfaces — Produces:**
- `<x-admin.rich-text wire:model="form.body" :label="…" :readonly="…" />`: a toolbar (paragraph/H2/H3/H4, bold, italic, underline, strike, bullet and numbered list, quote, link, image, undo, redo) over the editor in a `wire:ignore` block. Alpine component `richText` registered in `app.js`, whose Tiptap code is loaded with `import('./rich-text.js')` on init. It writes the HTML into the bound Livewire property without a request (`$wire.set(name, html, false)`) on change, debounced.
- Image node: renders `<img data-media-id alt>`; in the editor it shows the file through a `src` the picker handed over; `src` is never part of the saved HTML's meaning (the server strips it).
- Picker, detached mode: `<livewire:media-picker detached accept="image" />` renders only the modal. It opens on the browser event `media-picker:open` and, on a choice, dispatches `media-picker:picked` with `{id, url, alt, width, height}` and closes. The modelable field mode is unchanged.
- An entry opened for editing needs each body image's current address: the form passes a map `id => url` to the component (`:images`).

- [ ] **Step 1: Failing tests**: the detached picker renders no field UI; calling its choose method with an image dispatches `media-picker:picked` with the id, URL and alt; a document id or a foreign id is refused; without `media.view` it does not open. The field mode's existing tests stay green.
- [ ] **Step 2: Run, expect failures**
- [ ] **Step 3: Implement**
- [ ] **Step 4: Pass**, `npm run build`; check that the built `app` entry did not grow by the editor (the editor is a separate chunk) and state the chunk sizes in the report
- [ ] **Step 5: Spec + CLAUDE.md stack table + commit** "Add the rich text editor and a picker mode it can open". The report lists what only a browser can verify: typing, toolbar states, link dialog, image insert, undo.

---

### Task 9: Admin form (create, edit, translate)

**Files:**
- Create: `app/Livewire/Forms/EntryForm.php`, `app/Livewire/Admin/EntryFormPage.php` (base class, decision 32), `resources/views/pages/admin/content/⚡create.blade.php`, `⚡edit.blade.php`, `resources/views/partials/admin/content/entry-form.blade.php`, `resources/views/partials/admin/content/fields/{text,textarea,rich-text,number,date,toggle,select,media-file,gallery}.blade.php`
- Modify: `routes/admin.php`, `lang/{en,uk}/content.php`
- Test: `tests/Feature/Admin/Content/EntryFormTest.php`

**Interfaces — Consumes:** `CreateEntry`, `UpdateEntry`, `TrashEntry`, `EntryInput`, `EntryAuthors`, `EntryPaths::suggest()`, `Entry::copyFor()`, `<x-admin.rich-text>`, `<livewire:media-picker>`, `translations-grid`.

**Interfaces — Produces:**
- Routes `admin/content/{type}/create` (`admin.content.create`, query `locale`, optional `from`) and `admin/content/{type}/{entry}/edit` (`admin.content.edit`; a record of another type is 404; a trashed record opens read-only with Restore).
- `create?from={entry}&locale=xx`: when the group already has that language, redirect to its edit page; else the form is filled from `copyFor()`. Nothing is stored before Save.
- Layout as the mockup's form screen. Left: card with title, address (the site address and the parent's path as a prefix, the slug editable; the slug follows the title until edited by hand, then stops), body, excerpt; a card with the type's per-language fields when any; "Search engines" card with a live preview. Right: "Publication" (status select, date and time; a hint when the date is in the future), "Language and translations" (hidden with one language), "For all languages" (shared fields, with the line that a change applies to every translation; absent when the type has none), "Image", "Attributes" (parent for a hierarchical type as a select with indented options excluding the record's own subtree; author), "Move to trash".
- Save redirects to the edit page with a toast (decision 8); validation errors sit on their fields; a leave-page warning when the form is dirty (the pattern of `pages/admin/settings/⚡edit.blade.php`).
- With `view` only: every control disabled, no Save, no trash.
- Title of the page: the type's label and the record's title, through `->title()`.
- `EntryFormPage::suggestSlug(): string`: called from Alpine while the slug still follows the title; returns `EntryPaths::suggest()` for the form's title, parent and language without rendering the page (`#[Renderless]`).

- [ ] **Step 1: Failing tests**: create a page through the form (record stored, redirect, toast); `suggestSlug()` returns a free slug and a suffixed one when taken; edit changes title, parent and slug; `from` fills the form and saves into the same group; `from` with an existing translation redirects; a tampered `locale` or `parent_id` is a field error; `view` only renders a read-only form and refuses `save`; trash from the form asks for confirmation and redirects to the list; a type with fields (`ProductsType`) renders one control per field and stores local and shared values in the right place; the translations grid shows published, draft and missing states and links.
- [ ] **Step 2: Run, expect failures**: `php artisan test --compact tests/Feature/Admin/Content/EntryFormTest.php`
- [ ] **Step 3: Implement**
- [ ] **Step 4: Pass**, `npm run build`
- [ ] **Step 5: Spec + commit** "Add the entry form with translations"

---

### Task 10: Public pages

**Files:**
- Create: `app/Support/Content/ContentRouter.php`, `app/Support/Content/ContentMatch.php`, `app/Http/Controllers/ContentController.php`, `resources/views/layouts/site.blade.php`, `resources/views/site/entry.blade.php`, `resources/views/partials/site/language-switcher.blade.php`
- Modify: `routes/web.php`, `app/Support/Localization/ContentLocales.php`, `resources/views/partials/hreflang.blade.php` (unchanged call, new source), `docs/specs/languages.md`
- Test: `tests/Feature/Content/PublicContentTest.php`

**Interfaces — Produces:**
- Route inside `Route::localized()`: `Route::get('{path}', ContentController::class)->where('path', '.*')->fallback()->name('content')`. Both copies are fallbacks, so every other route (admin, Fortify, media, Livewire) wins; the prefixed copy still precedes the plain one. Check that `ReservedSlugs` is unaffected by a route whose first segment is a parameter.
- `ContentRouter::resolve(string $locale, string $path, ?User $viewer): ?ContentMatch`: a type base of that language first (a record under it; the list itself is Part 2 and resolves to null for now), then a page by `type, locale, path`; a record that is not public resolves only for a viewer who may edit its type (`preview: true`); else a redirect row → `ContentMatch::redirect($url)`; else null.
- `ContentMatch`: `entry`, `preview`, `redirectTo`.
- `ContentController`: 301 for a redirect, 404 for null, else the `site.entry` view with the entry, the rendered body, and the public translations (one query). A trailing slash or uppercase path is not special-cased beyond what the router gives.
- `ContentLocales::useAlternates(array<string, string> $alternates): void`: when called, `alternates()` returns exactly these (code → address). The controller passes the addresses of the translations that are public and whose language is active. The switcher partial lists every active language: the translation's address when present, else `ContentLocales::route('home', [], $code)`.
- `layouts.site`: `<html lang dir>`, `partials.head` pieces the public side needs, `<title>` (`seo_title`, else title and site name), `hreflang`, site logo or name linking home, the switcher, a preview strip "Draft" or "Scheduled for <date>" with `<meta name="robots" content="noindex">` in preview. Neutral typography for the body (headings, lists, quotes, images `max-width: 100%`), using the scheme's variables.

- [ ] **Step 1: Failing tests**: `/about` shows a published `uk` page and `/en/about-us` its translation; a nested path; a draft, a scheduled and a trashed page are 404 for a guest and for a user without `pages.edit`, and open with the strip and `noindex` for one with it; `hreflang` lists only public translations in active languages plus `x-default`, and none when there is one; the switcher links `en` to the translation and `de` (no translation) to `/de`; an old path answers 301 to the new one, in both languages; `/admin`, `/login`, `/media/…` and the home page are untouched; `/de/about` is the German site, not a page `de/about`; a page view makes at most 5 queries more than the home page; the routes still serialize for the route cache (as `ContentLanguagesTest` checks).
- [ ] **Step 2: Run, expect failures**: `php artisan test --compact tests/Feature/Content/PublicContentTest.php`
- [ ] **Step 3: Implement**
- [ ] **Step 4: Pass**, then `composer test` (the whole suite, Pint, PHPStan) and `npm run build`
- [ ] **Step 5: Specs + CLAUDE.md decisions (types registry and storage; one record per language; the wildcard route; the editor and sanitizer) + commit** "Serve pages on the site with redirects, hreflang and a language switcher"

---

### After the tasks

- [ ] Independent review of the branch against the spec and this plan, with the Review Focus list; fix what it finds.
- [ ] Hand the branch to the user for the browser check, with the list of what only a browser shows: the editor (typing, toolbar, link, image from the library, undo), the leave-page warning, the form at phone width, dark mode and a color scheme on the new screens, a 12-language site in the translations card and the list.
- [ ] Merge only on the user's word; then write the plan for Part 2.

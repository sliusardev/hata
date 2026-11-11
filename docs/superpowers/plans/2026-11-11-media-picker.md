# Media library Part 4: media picker and library logos

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** A reusable media picker modal (library + upload) for admin forms, first used to choose the site logos from the media library, with readable "where it's used" labels.

**Architecture:** Usables name themselves through an interface (`UsesMedia`) that `MediaUsage` asks for a label, a link and a "forget" hook run before a file is deleted. The library page's filter query and upload storing move into shared support classes so the picker reuses them. The picker is a Livewire SFC bound with `#[Modelable]`; parents validate its value with a `MediaSelection` rule. Logos become media ids in settings; `SiteLogo` resolves them to `Media` and the `branding/` storage, route and upload action go away, with a migration importing existing logos.

**Tech Stack:** Laravel 13, Livewire 4 SFC (`#[Modelable]`), Flux 2.21 free, Alpine (`mediaUploader`), Pest 5.

**Spec:** `docs/superpowers/specs/2026-11-11-media-picker-design.md` (approved 2026-11-11), on top of `docs/specs/media.md` (Picker, screen 5; `media_usages`) and `docs/specs/site-settings.md` (Logo). Mockup: screen 5 of https://claude.ai/artifact/4ht3hKQ37R8oawQZFBCoi4.

## Global Constraints

- Picker permissions: modal needs `media.view`; "Upload new" tab needs `media.upload`; alt read-only without `media.edit`; without `media.view` the field shows the current choice, "Remove" and a hint.
- Options: `accept` = `image` | `document` | `any`; `multiple` (ordered id list, order of picking, no reordering); optional `extensions` (files outside shown disabled with "This format can't be used here", uploads outside refused, parent re-checks).
- Logo slots: `extensions` = `png`, `jpg`, `jpeg`, `webp`, `svg`; single; choosing saves at once (toast, tab reloads); "Remove" unlinks only; library image size limit applies; logo `alt` = `config('app.name')`; one unusable slot counts as empty and the other covers both themes; both unusable → default mark.
- Deleting a used file is allowed with a warning naming the usages; deleting a logo file clears its setting.
- "Site logo (light|dark)" usage links to `admin.settings.edit?tab=logo` only for `settings.edit`; plain text otherwise.
- Existing `branding/` logos are imported once by `php artisan migrate` (no SSH); a file that can't be imported leaves its slot empty and never fails the migration.
- Flux free only; every string in `lang/en` and `lang/uk`; specs (`media.md`, `site-settings.md`) updated in the same commit; CLAUDE.md decision 39 marked superseded and a new decision 49 added at the end of the list (never touch the Boost block).
- Before each commit: `vendor/bin/pint --dirty --format agent`, `composer types:check`, affected tests; `npm run build` when Tailwind classes or JS change. Messages end with `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`.

## Review Focus

1. A tampered picker value (id of a document, a deleted file, a GIF for the logo, an array for a single field): the parent's save refuses with a field error, nothing stored (tests in Tasks 3 and 4).
2. A logo whose media row or file was deleted outside the app: the site renders the other slot or the default mark, never a broken `<img>` (test in Task 4).
3. Deleting a folder that contains the logo file: the logo setting is cleared like a single delete (test in Task 1).
4. A user with `settings.edit` but without `media.view` / `media.upload`: the logo tab shows no picker / no upload tab, and calling the picker's methods directly is refused (tests in Tasks 3 and 4).
5. The import migration on an install whose `branding/` file is missing or not an image: migration succeeds, slot empty (test in Task 5).

---

### Task 1: Usage labels and the forget hook

**Files:**
- Create: `app/Contracts/UsesMedia.php`
- Modify: `app/Models/MediaUsage.php`, `app/Actions/Media/DeleteMedia.php`, `app/Actions/Media/DeleteFolder.php`, `resources/views/partials/admin/media/details.blade.php` (usage list), `resources/views/partials/admin/media/dialogs.blade.php` (delete dialogs), `lang/{en,uk}/media.php`, `docs/specs/media.md`
- Test: `tests/Feature/Models/MediaUsageTest.php`, existing delete/details tests

**Interfaces — Produces:**
- `interface UsesMedia { public function mediaUsageLabel(string $field): string; public function mediaUsageUrl(string $field, ?User $viewer): ?string; public function forgetMedia(string $field, int $mediaId): void; }`
- `MediaUsage::label(): string` — the usable's label when it implements `UsesMedia`, else `media.details.usage_item` ("Type #id") as today; a usable row that no longer exists → the fallback.
- `MediaUsage::url(?User $viewer): ?string` — delegates, else null.
- `MediaUsage::forgetAll(iterable<Media> $media): void` — for each usage whose usable implements `UsesMedia`, calls `forgetMedia($field, $mediaId)`. `DeleteMedia` and `DeleteFolder` call it inside their transactions before rows are deleted.
- Details panel and every delete dialog render `label()` (a link when `url()` is not null).

- [ ] **Step 1: Failing tests** — with a test-only model implementing `UsesMedia` (anonymous class over an existing table, or a fixture model in `tests/Fixtures`): `label()`/`url()` delegate; a usage of a plain model keeps "Type #id"; `DeleteMedia` and `DeleteFolder` (file inside a subfolder) call `forgetMedia` with the right field and id; details panel and delete dialog show the label and the link.
- [ ] **Step 2: Run, expect failures** — `php artisan test --compact tests/Feature/Models/MediaUsageTest.php`
- [ ] **Step 3: Implement**
- [ ] **Step 4: Pass** — `php artisan test --compact --filter=Media`
- [ ] **Step 5: Spec + commit** "Let media usages name themselves and react to deletes"

---

### Task 2: Share the library's filters and upload storing

**Files:**
- Create: `app/Support/Media/MediaFilters.php`, `app/Support/Media/StoreUploadedMedia.php`
- Modify: `resources/views/pages/admin/media/⚡index.blade.php` (`filteredQuery()`, `monthRange()`, `storeUpload()` delegate), `docs/specs/media.md`
- Test: `tests/Feature/Media/MediaFiltersTest.php`; the existing library tests are the regression net

**Interfaces — Produces:**
- `final readonly class MediaFilters { __construct(string $search = '', string $type = 'all', string $month = '', ?MediaFolder $folder = null, bool $noFolder = false, bool $unused = false, ?array $extensions = null); apply(Builder $query): Builder; static monthRange(string $month): ?array }` — same semantics as the library today (month in `admin.timezone` → UTC range, `MediaKind::tryFrom($type)`, `search()` scope); `extensions` filters on the current name's extension (lowercase) when given — used by the picker's "show only allowed" toggle if any; disabling (not hiding) is the picker's default.
- `StoreUploadedMedia::handle(User $actor, TemporaryUploadedFile $file, ?int $folderId, ?int $pickedSize, ?string $pickedExtension, ?array $allowedExtensions = null): array{ok: bool, id?: int, name?: string, meta?: array, error?: string}` — exactly the current `storeUpload()` behaviour (folder missing, `MediaUploadException`, unexpected errors reported, temp file deleted); with `$allowedExtensions` a file whose stored extension is outside it is refused with `media.picker.not_allowed` and nothing stored.

- [ ] **Step 1: Failing tests** — `MediaFilters` (search, type, month boundaries in a non-UTC timezone, folder/none, unused, extensions); `StoreUploadedMedia` refuses a disallowed extension and stores nothing.
- [ ] **Step 2: Run, expect failures**
- [ ] **Step 3: Implement; the library page delegates (no behaviour change)**
- [ ] **Step 4: Pass** — new tests plus `tests/Feature/Admin/Media`
- [ ] **Step 5: Commit** "Share the media library's filters and upload storing"

---

### Task 3: The picker component and the `MediaSelection` rule

**Files:**
- Create: Livewire SFC `media-picker` (place it where Livewire 4 resolves `<livewire:media-picker>` without published config; follow the `livewire-development` skill), `app/Rules/MediaSelection.php`, `resources/views/partials/admin/media/picker-*.blade.php` as needed
- Modify: `lang/{en,uk}/media.php` (`media.picker.*`), `docs/specs/media.md` (Picker section → Built), `resources/js` only if the uploader needs a hook
- Test: `tests/Feature/Admin/Media/MediaPickerTest.php`, `tests/Feature/Rules/MediaSelectionTest.php`

**Interfaces:**
- Consumes: `MediaFilters`, `StoreUploadedMedia` (Task 2); `UpdateMedia` (alt), `MediaFolder::tree()`, `Media::thumbUrl()`, the `mediaUploader` Alpine component.
- Produces:
  - Usage: `<livewire:media-picker wire:model="form.field" accept="image" :multiple="false" :extensions="[...]" :label="__('…')" />`; `#[Modelable] public int|array|null $value` (int|null single, `list<int>` multiple).
  - Form side: preview (thumbnail or file icon), name, "Change"/"Choose", "Remove"; for multiple a list with remove buttons, order kept.
  - Modal (`flux:modal`, name unique per instance): tabs "Library" / "Upload new" (only with `media.upload`); library tab: search (300 ms), type fixed by `accept`, month select, folder `flux:select` (indented tree, "All files", "No folder"), grid of thumbnails loaded 30 at a time with "Load more"; files outside `accept`/`extensions` shown disabled with `media.picker.not_allowed`; click selects (toggles with `multiple`), double click selects and closes; side panel: preview, name, size, dimensions, primary-language alt (editable with `media.edit` through `UpdateMedia`, note `media.picker.alt_note` "Alt text changes in the library, everywhere this file is used"), "Select" button. Upload tab: the uploader into the chosen folder; each stored file is selected (single → replaces the choice) and the tab switches to Library.
  - Every server method authorizes (`viewAny` on Media for listing/selecting, `create` for uploads, `update` for alt); ids coming from the browser are re-checked against `accept`/`extensions` before they're accepted into `$value`.
  - `new MediaSelection(accept: 'image', multiple: false, extensions: [...])` validation rule: value shape (int or list of ints), every id exists, kind matches `accept`, extension in `extensions`; messages `media.picker.invalid`.

- [ ] **Step 1: Failing tests** — rule: rejects array for single, unknown id, document for image, gif for logo extensions; accepts valid. Component (mount inside a tiny test-only parent component, or test the picker directly with `Livewire::test` and assert the modelable value): lists only allowed kinds, disabled marking for other extensions, select/double-click sets value, multiple keeps order and toggles, upload stores and selects, alt edit saves through `UpdateMedia` and is refused without `media.edit`, no upload tab and refused upload without `media.upload`, every method refused without `media.view`, tampered id (document, missing) is ignored.
- [ ] **Step 2: Run, expect failures**
- [ ] **Step 3: Implement** (load `livewire-development`, `fluxui-development`)
- [ ] **Step 4: Pass**, `npm run build`
- [ ] **Step 5: Spec + commit** "Media picker for admin forms"

---

### Task 4: Logos from the library

**Files:**
- Create: `app/Actions/Settings/SetLogo.php`
- Modify: `app/Support/Settings/SiteLogo.php`, `app/Models/Setting.php` (implements `UsesMedia` for `logo_light`/`logo_dark`), `app/Actions/Settings/RemoveLogo.php`, `resources/views/components/site-logo.blade.php`, `resources/views/pages/admin/settings/⚡edit.blade.php` (logo tab, drop `logoLight`/`logoDark` uploads), `app/Support/Settings/SiteSettings.php` (casts for the two keys: int), `config/admin.php` (logo block: ids, no disk), `routes/web.php`, `lang/{en,uk}/site_settings.php`, `docs/specs/site-settings.md`, `docs/specs/media.md`, `CLAUDE.md` (decision 39 superseded → 49)
- Delete: `app/Actions/Settings/UploadLogo.php`, `app/Http/Controllers/SiteLogoController.php` and their tests (replace with new ones; ask nobody — the design removes them)
- Test: `tests/Feature/Admin/Settings/…Logo…Test.php` (rewrite), `tests/Feature/Support/Settings/SiteLogoTest.php`

**Interfaces:**
- Consumes: `UsesMedia`, `MediaUsage::sync` (Task 1), picker + `MediaSelection` (Task 3).
- Produces:
  - `SiteLogo::media(string $variant): ?Media` — this variant's media, else the other's; a missing row, a missing file or a non-image (kind/extension outside the logo list) counts as empty. `SiteLogo::url(string $variant): ?string` = `media(...)?->url()`; `isCustom()` unchanged in meaning. Per-request memoized.
  - `SetLogo::handle(User $actor, string $variant, int $mediaId): void` — `settings.edit`; validates with `MediaSelection(accept: 'image', extensions: logo list)`; stores the id through `SiteSettings::put()`; `MediaUsage::sync($settingRow, "logo_{$variant}", [$id])`.
  - `RemoveLogo::handle(User $actor, string $variant): void` — removes the setting and its usage row; the file stays.
  - `Setting` as `UsesMedia`: label `site_settings.logo.usage_light|usage_dark` ("Site logo (light)" / "Site logo (dark)"), url to `admin.settings.edit` `tab=logo` when the viewer can `settings.edit`, `forgetMedia()` removes that logo setting (via `SiteSettings::put([$key => null])`).
  - Logo tab: per slot the preview on its background, the picker (`accept` image, single, logo extensions) bound to a page property; when it changes the page calls `SetLogo` (toast `site_settings.logo.chosen`, redirect to the tab); "Remove" as today; without `media.view` no picker and the hint `site_settings.logo.needs_media`.
  - `<x-site-logo>` uses `SiteLogo::url()`; `alt` stays the site name.
  - Route `branding.logo`, `SiteLogoController`, `UploadLogo`, `admin.logo.disk` removed.

- [ ] **Step 1: Failing tests** — choosing stores the id and a usage row, toast, redirect; GIF / document / missing id refused with a field error and nothing stored; remove unlinks, file stays, usage row gone; only the dark logo set → used in both themes; light logo's file deleted on disk → dark used for both; both gone → default mark (no `<img>` with a dead URL); deleting the logo file in the library clears the setting (and through a folder delete); usage label/link in the details panel (link only with `settings.edit`); without `media.view` no picker and hint shown; `branding/logo/light` route no longer exists (404).
- [ ] **Step 2: Run, expect failures**
- [ ] **Step 3: Implement**
- [ ] **Step 4: Pass**, then the whole suite
- [ ] **Step 5: Specs + CLAUDE.md + commit** "Choose the site logos from the media library"

---

### Task 5: Import existing logos

**Files:**
- Create: migration `import_branding_logos_into_media` (`php artisan make:migration`)
- Modify: `app/Actions/Media/UploadMedia.php` (`import()`)
- Modify: `docs/specs/site-settings.md` (upgrade note)
- Test: `tests/Feature/Migrations/ImportBrandingLogosTest.php`

**Interfaces — Produces:** `UploadMedia::import(UploadedFile $file, ?MediaFolder $folder): Media` — the same checks, optimization and storing as `handle()` (one code path: `handle()` authorizes and then calls the shared internals), without an actor: `uploaded_by` null (the column is nullable). Used only by this migration (like decision 16). Imported files go into a top-level folder named `Logo`, created if missing (reuse a same-named top-level folder).

- `up()`: for each of the old settings `logo_light`/`logo_dark` whose value is a string path that exists on `config('admin.logo.disk')` (read the old disk name from the setting row or the old default `local`): import it as a media file, store its id as the setting value, record the usage, delete the old file. Anything that fails (missing file, not an image, decode error) → remove that setting, report the exception, continue. Settings already holding an int are left alone (idempotent).
- `down()`: no-op (documented).

- [ ] **Step 1: Failing tests** — a PNG in `branding/` becomes a media row in folder "Logo" with the setting = its id and a usage row; a missing file → setting removed, migration succeeds; a text file → same; running `up()` twice changes nothing the second time.
- [ ] **Step 2: Run, expect failures**
- [ ] **Step 3: Implement**
- [ ] **Step 4: Pass**, whole suite on SQLite and on a throwaway MySQL database
- [ ] **Step 5: Spec + commit** "Import uploaded logos into the media library"

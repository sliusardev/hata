# Local images served by the web server — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Raster images and thumbnails stored on this server are fetched from the web server as static files (Laravel's `public` disk + `storage:link`), with a silent fallback through the app and an admin warning when the link does not work.

**Architecture:** The site's media disk becomes `public`. One placement rule decides the disk of each row (rasters → `public`; SVG and non-images → private `local`); originals of `public` rows live on `local`. The `public` disk versions its paths and defers deletion like S3. A `DirectServing` service (link `stat` + a browser-confirmed probe stored in site settings) gates `Media::directUrl()`; everything else falls back to the existing media route.

**Tech Stack:** Laravel 13, Livewire 4 SFC, Flux free, Pest 5, PHPStan level 7, Pint.

**Spec:** `docs/superpowers/specs/2026-11-12-media-direct-local-design.md` (read it first; also `docs/specs/media.md`, `docs/specs/site-settings.md`, CLAUDE.md decisions 41, 45, 46, 50, 51).

## Global Constraints

- No new dependencies. Flux free components only.
- Every new translation key in both `lang/en` and `lang/uk` (`TranslationParityTest` enforces it).
- Per-record actions and confirmations follow CLAUDE.md decisions 8, 27, 35.
- PHP: curly braces always, typed signatures, PHPDoc over inline comments. Run `vendor/bin/pint --dirty --format agent` before each commit.
- Tests: Pest feature tests, factories, `Storage::fake`. Never delete an existing test; update it when its assumption changed. Run the narrowest tests per step, `composer test` at the end of each task.
- Commit after each task on branch `media-direct-local`; messages are plain sentences in the repo's style and end with `Co-Authored-By: Claude Fable 5.2 <noreply@anthropic.com>`.
- SVG, kept originals and non-image files must never be written under the `public` disk.
- S3 behaviour must not change: `CloudDiskFlowsTest`, `S3IntegrationTest`, `MoveMediaToDiskTest` stay green without loosening assertions.

## Review Focus

1. A PNG on `public` replaced by an SVG (or SVG by PNG): refused with a message, nothing written to `public`. (Task 3)
2. Link present, web server not serving it, or wrong `APP_URL`: pages keep using the app route until a probe succeeds. (Tasks 4, 6)
3. A static `/storage/media/...` address requested while the link is missing: the app serves the raster; an SVG, an original or a path outside `media/` gets 404. (Task 5)
4. A rebuild (crop/replace/restore) of a `public` row that fails midway: no orphan on either disk, old version still served. (Task 3)
5. A host that forbids `symlink()`: the button reports it with the SSH command instead of an error page. (Task 6)

---

### Task 1: Disk capabilities, placement rule and paths

**Files:**
- Modify: `app/Support/Media/MediaDisks.php`, `app/Support/Media/MediaPaths.php`, `app/Models/Media.php`, `config/media.php`, `.env.example`, `phpunit.xml`, `tests/Pest.php`
- Test: `tests/Feature/Media/MediaDisksTest.php` (new, `php artisan make:test --pest Media/MediaDisksTest`), `tests/Feature/Media/MediaPathsTest.php`

**Interfaces — Produces:**
- `MediaDisks::PUBLIC = 'public'`, `MediaDisks::PRIVATE = 'local'`
- `MediaDisks::isCloud(string $disk): bool` — unchanged meaning (remote).
- `MediaDisks::publishes(string $disk): bool` — local driver **and** a public URL (true for `public`, false for `local` and `s3`).
- `MediaDisks::versionsPaths(string $disk): bool` — `isCloud($disk) || publishes($disk)`.
- `MediaDisks::originalsDisk(string $disk): string` — `self::PRIVATE` when `publishes($disk)`, else `$disk`.
- `MediaDisks::diskFor(string $siteDisk, MediaKind $kind, string $mime): string` — when `publishes($siteDisk)`: `$siteDisk` for `MediaKind::Image` whose mime is not `image/svg+xml`, else `self::PRIVATE`; otherwise `$siteDisk`.
- `Media::originalsDisk(): string` — `MediaDisks::originalsDisk($this->disk)`.
- `tests/Pest.php`: `usePublicMediaDisk(): void` — `Storage::fake('public')`, `Storage::fake('local')`, `config(['media.disk' => 'public'])`.

- [ ] **Step 1:** Write failing tests: each capability for `local`, `public`, `s3` (set `filesystems.disks.s3.driver` as existing cloud tests do); `diskFor` for webp/svg/pdf under site disks `public`, `local`, `s3`; `MediaPaths::file/thumb/original('public', $key, 3, ...)` contain `{$key}-v3`, and for `local` do not.
- [ ] **Step 2:** Run them; expect failures for undefined methods.
- [ ] **Step 3:** Implement. `MediaPaths::name()` uses `versionsPaths()`. `MediaPaths::writeOptions()` keeps using `isCloud()`. `config/media.php` default becomes `env('MEDIA_DISK', 'public')` with the comment rewritten; `.env.example` documents `MEDIA_DISK`. `phpunit.xml` adds `<env name="MEDIA_DISK" value="local"/>` so the existing suite keeps exercising private-disk rows (still used for SVG and documents); new tests opt in with `usePublicMediaDisk()`.
- [ ] **Step 4:** Run the two test files, then `composer test`. Expected: green.
- [ ] **Step 5:** Commit.

### Task 2: Uploads land on the right disk; originals stay private

**Files:**
- Modify: `app/Actions/Media/UploadMedia.php`, `app/Support/Media/StoreUploadedMedia.php` (`discard`), `app/Actions/Media/DeleteMedia.php`, `app/Actions/Media/DeleteFolder.php`, `app/Support/Media/MediaSource.php`, `app/Actions/Media/RestoreOriginal.php`, `app/Http/Controllers/MediaSourcePreviewController.php`, `app/Actions/Media/PruneMediaDeletions.php`, any other reader of `original_path` found by `grep -rn original_path app`
- Test: `tests/Feature/Actions/Media/PublicDiskUploadTest.php` (new)

**Interfaces:** Consumes Task 1. Produces no new names; the rule every later task relies on: *file and thumbnail are on `$media->disk`; the original is on `$media->originalsDisk()`; the original's path is named with the row's disk (`MediaPaths::original($media->disk, ...)`), so it is versioned for `public` rows.*

- [ ] **Step 1:** Write failing tests (all with `usePublicMediaDisk()`):
  - a JPEG upload with `keep_originals` on: row `disk` is `public`; `path` and `thumb_path` exist on `public`; `original_path` exists on `local` and not on `public`; paths contain `-v1`;
  - an SVG upload and a PDF upload: row `disk` is `local`, nothing written to `public`;
  - deleting the JPEG row removes all three objects from their disks (or schedules them, matching how cloud rows are handled today — read `DeleteMedia` and keep its existing rule per disk);
  - `MediaSource::for()` reads the original from `local`; `RestoreOriginal` finds it;
  - a failed write (make the `public` disk's `put` return false, as `UploadMediaTest` does for cloud) leaves no object on either disk;
  - the prune's "still referenced" check does not delete an original on `local` that a `public` row references.
- [ ] **Step 2:** Run; expect failures.
- [ ] **Step 3:** Implement: `UploadMedia::store()` resolves `$disk = MediaDisks::diskFor((string) config('media.disk'), $type['kind'], $mime)` after optimization (the stored mime decides) and writes the original through `Storage::disk(MediaDisks::originalsDisk($disk))`; cleanup deletes per disk. Every listed reader/deleter splits `[path, thumb_path]` on the row's disk from `original_path` on `originalsDisk()`.
- [ ] **Step 4:** Run the new file plus `tests/Feature/Actions/Media`, `tests/Feature/Media`; then `composer test`.
- [ ] **Step 5:** Commit.

### Task 3: Rebuilds on the public disk; replace stays on its side of the SVG line

**Files:**
- Modify: `app/Actions/Media/RebuildMediaFile.php`, `app/Actions/Media/ReplaceMediaFile.php`, `app/Support/Media/MediaPaths.php` (`write` guard), `app/Exceptions/MediaWriteException.php`, `lang/{en,uk}/media.php`
- Test: `tests/Feature/Actions/Media/PublicDiskRebuildTest.php` (new), `tests/Feature/Actions/Media/ReplaceMediaFileTest.php`

**Interfaces — Produces:**
- `MediaPaths::write()` throws `MediaWriteException::svgOnPublishingDisk(string $disk, string $path)` when `MediaDisks::publishes($disk)` and `$mime === 'image/svg+xml'`.
- Translation key `media.replace.svg_boundary`.

- [ ] **Step 1:** Write failing tests:
  - crop of a `public` row: new `-v2` objects on `public`, the `-v1` file and thumbnail still exist and are rows in `media_pending_deletions` (disk `public`), version is 2;
  - replace with `keep_originals`: new original on `local` at a `-v2` path, old original scheduled with disk `local`;
  - a rebuild whose DB transaction throws: `-v2` objects removed from both disks, row unchanged, `-v1` objects intact;
  - replacing a PNG by an SVG and an SVG by a PNG throws the existing replace exception type with `__('media.replace.svg_boundary')`, on any site disk; nothing is written;
  - `MediaPaths::write` on `public` with `image/svg+xml` throws.
- [ ] **Step 2:** Run; expect failures.
- [ ] **Step 3:** Implement. In `RebuildMediaFile`, the flag now named `$cloud` (line ~198) becomes "versions paths" (`MediaDisks::versionsPaths`): no backups, deferred deletion. Originals are written, undone and scheduled on `$media->originalsDisk()`; stale paths are scheduled per the disk each lives on. `ReplaceMediaFile` compares "is SVG" of the row and of the new file before anything else.
- [ ] **Step 4:** Run the new file, `RebuildMediaFileTest`, `ReplaceMediaFileTest`, `CropMediaTest`, `RestoreOriginalTest`, `CloudDiskFlowsTest`; then `composer test`.
- [ ] **Step 5:** Commit.

### Task 4: DirectServing state and direct URLs

**Files:**
- Create: `app/Support/Media/DirectServing.php`
- Modify: `app/Models/Media.php` (`directUrl`), `app/Support/Settings/SiteSettings.php` (three stored keys, not mapped to config forms), `app/Providers/AppServiceProvider.php` (singleton)
- Test: `tests/Feature/Media/DirectServingTest.php` (new), `tests/Feature/Models/MediaUrlTest.php`

**Interfaces — Produces** (`App\Support\Media\DirectServing`, bound as a singleton; tests replace it with `DirectServing::fake(bool $linked = true, bool $confirmed = true): void`, and `usePublicMediaDisk()` gains an optional `bool $direct = false` that calls it):
- `linked(): bool` — the link configured in `filesystems.links` whose target is the `public` disk's root exists and resolves to it; memoized per instance.
- `state(): string` — one of `'direct'`, `'unchecked'`, `'link_missing'`, `'unreachable'`. `link_missing` when not linked; `direct` when the stored probe state is `ok` **and** its stored URL equals `MediaDisks::publicUrl('public')`; `unreachable` when the stored state is `failed` for that URL; else `unchecked`.
- `active(string $disk): bool` — `MediaDisks::publishes($disk) && state() === 'direct'`.
- `probeUrl(): string` — `{public url}/media/.probe`; `probeToken(): string` — writes `media/.probe` on the `public` disk with a random token when absent and returns its content.
- `record(bool $ok, string $url): void` — stores `media_direct_state` (`ok`/`failed`), `media_direct_url`, `media_direct_checked_at`; flushes the memo.
- `dueForCheck(): bool` — true when `state()` is `unchecked` or `unreachable`, or the last check is older than 24 hours; false when `link_missing`.

`Media::directUrl()` returns a URL when (`isCloud` and a public URL — unchanged) **or** `app(DirectServing::class)->active($this->disk)`; the SVG and non-image exclusions stay.

- [ ] **Step 1:** Write failing tests: the state table above (link faked through a temp directory and `config(['filesystems.links' => ...])`, or the seam the implementer adds for `linked()`); a URL change turns `direct` into `unchecked`; `Media::url()`/`thumbUrl()` of a `public` raster are `/storage/media/...-v1.webp?v=1` when direct and the `media.show` route URL otherwise; an S3 row's URL is unaffected by the state.
- [ ] **Step 2:** Run; expect failures.
- [ ] **Step 3:** Implement.
- [ ] **Step 4:** Run the two files and `tests/Feature/Admin/Media`; then `composer test`.
- [ ] **Step 5:** Commit.

### Task 5: Routes — redirect on the permanent address, fallback for static addresses

**Files:**
- Modify: `app/Http/Controllers/MediaController.php`, `routes/web.php`, `config/filesystems.php` (`local` disk: `'serve' => false`)
- Create: `app/Http/Controllers/StaticMediaFallbackController.php`
- Test: `tests/Feature/Media/MediaControllerTest.php`, `tests/Feature/Media/StaticMediaFallbackTest.php` (new)

**Interfaces — Produces:** route `media.static` = `GET storage/media/{path}` (`path` matches `.+`), `->withoutMiddleware('web')`.

- [ ] **Step 1:** Write failing tests:
  - `media.show` for a `public` raster with direct serving active: uncacheable 302 to `$media->url()`; with `?download=1`: streamed attachment; with direct serving inactive: streamed 200 with today's headers; thumbnail likewise;
  - `media.static`: serves a raster file or thumbnail that some `public` row references by `path` or `thumb_path` (200, `nosniff`, `Cache-Control: public, max-age=604800`, correct type); 404 for an unknown path, for `..` segments, for a path on `public` that no row references, and for any SVG; no cookies on the response.
- [ ] **Step 2:** Run; expect failures.
- [ ] **Step 3:** Implement. The fallback controller looks the row up by `disk = public` and `path`/`thumb_path` equal to `media/{path}`; it never reads the `local` disk. Reuse the streaming code of `MediaController` rather than copying it (extract what both need).
- [ ] **Step 4:** Run both files; then `composer test`.
- [ ] **Step 5:** Commit.

### Task 6: Probe endpoint, admin probe script, "Create the storage link"

**Files:**
- Create: `app/Actions/Media/CreateStorageLink.php`, `app/Http/Controllers/Admin/MediaDirectCheckController.php`, `resources/js/media-direct-probe.js`
- Modify: `routes/admin.php`, `resources/js/app.js` (import), `resources/views/layouts/app/sidebar.blade.php` (config for the script, only for users who `can('settings.edit')` while `DirectServing::dueForCheck()` and the site disk publishes), `lang/{en,uk}/media.php` or `site_settings.php`
- Test: `tests/Feature/Admin/Media/MediaDirectCheckTest.php` (new), `tests/Feature/Actions/Media/CreateStorageLinkTest.php` (new)

**Interfaces — Produces:**
- `POST admin/media/direct-check` → `admin.media.direct-check`, body `{ok: bool, url: string}`, requires `settings.edit`; calls `DirectServing::record()`; `ok` is accepted only when `url === DirectServing::probeUrl()`; returns `{state: string}`.
- The script reads `window.mediaDirectProbe = {url, token, report}` (rendered by the layout), fetches `url` with `cache: 'no-store'`, compares the body to `token`, and POSTs the result with the CSRF token. It also exposes `window.checkMediaDirect(): Promise<string>` for the settings page's "Check again".
- `CreateStorageLink::handle(User $actor): void` — authorizes `settings.edit`, runs `Artisan::call('storage:link')`, verifies `DirectServing::linked()` afterwards; throws `App\Exceptions\ProtectedActionException`-style translated failure (`…link_failed`, containing `php artisan storage:link`) when the call throws or the link is still missing.

- [ ] **Step 1:** Write failing tests: endpoint 403 without `settings.edit`; `ok: true` with the right URL stores `direct`; with another URL stores nothing as ok; `ok: false` stores `unreachable`; the layout renders the probe config only when due and permitted; `CreateStorageLink` permission, success (link faked through `filesystems.links` pointing into a temp directory) and failure when the link cannot be created (target parent missing or `Artisan` faked to throw).
- [ ] **Step 2:** Run; expect failures.
- [ ] **Step 3:** Implement; `npm run build`.
- [ ] **Step 4:** Run the new files; then `composer test`.
- [ ] **Step 5:** Commit.

### Task 7: Storage setting values, Media tab status, library callout

**Files:**
- Create: migration `rewrite_local_media_storage_setting` (`php artisan make:migration`)
- Modify: `app/Livewire/Forms/SiteSettingsForm.php` (lines ~92, 144, 269), `app/Actions/Settings/UpdateSiteSettings.php`, `resources/views/pages/admin/settings/⚡edit.blade.php` (storage card), `resources/views/pages/admin/media/⚡index.blade.php` (callout), `lang/{en,uk}/site_settings.php`, `lang/{en,uk}/media.php`
- Test: existing site-settings tests for the Media tab, `tests/Feature/Admin/Media/MediaIndexTest.php`, a migration test beside the existing settings migration tests

**Interfaces:** Consumes `DirectServing::state()`, `CreateStorageLink`, `window.checkMediaDirect()`.

- [ ] **Step 1:** Write failing tests: the form accepts `public` and `s3`, rejects `local`; it loads as `public` unless the disk is `s3`; the migration turns a stored `media_storage = local` into `public` and leaves `s3` and an absent row alone; the storage card shows the right text for each of the four states, the "Create the storage link" button only for `link_missing`, "Check again" for `unchecked`/`unreachable`; the button calls `CreateStorageLink` and toasts success or the failure message; the library page shows the callout (link to the settings Media tab) only to users with `settings.edit` while the site disk is `public` and the state is `link_missing` or `unreachable`.
- [ ] **Step 2:** Run; expect failures.
- [ ] **Step 3:** Implement. Texts: status lines `site_settings.media.storage.direct.{direct,unchecked,link_missing,unreachable}` (the last takes `:url`), buttons `…create_link`, `…check_again`, toasts `…link_created`, `…link_failed`; callout `media.index.direct_warning`. Use `<flux:callout>`; follow the existing storage card's layout.
- [ ] **Step 4:** Run the touched test files and `tests/Feature/Localization`; then `composer test`.
- [ ] **Step 5:** Commit.

### Task 8: Moving files to and from "this server"

**Files:**
- Modify: `app/Actions/Media/MoveMediaToDisk.php`, `app/Console/Commands/` move command, `resources/views/pages/admin/media/⚡move.blade.php`, `app/Support/Media/DiskMoveRun*.php` if they hold the candidate query, `lang/{en,uk}/media.php`
- Test: `tests/Feature/Actions/Media/MoveMediaToDiskTest.php`, `tests/Feature/Media/MoveMediaBatchJobTest.php`, the move page and command tests

**Interfaces — Produces:**
- `MoveMediaToDisk::TARGETS = ['public', 's3']`.
- `MoveMediaToDisk::destinationFor(Media $media, string $target): string` — `MediaDisks::diskFor($target, $media->kind, $media->mime)`.
- Candidates of a run to `$target`: rows whose `disk` differs from `destinationFor()` — as one query: for target `public`, (`disk != 'public'` and raster image) or (`disk not in ('local')` and not raster image); for `s3`, `disk != 's3'`. Used by the count, `next()`, the page and the command.

- [ ] **Step 1:** Write failing tests: target `public` moves a raster from `local` to `public` (file + thumb on `public` at `-v{n+1}` paths, original on `local`), leaves an SVG and a PDF on `local` and does not count them; moves an S3 raster to `public` and an S3 PDF to `local`; target `s3` moves a `public` raster with its `local` original, and a `local` PDF; a failed copy leaves no objects on any destination disk and the row unchanged; `media:move-disk local` is refused with the list of targets; versions bump and the old objects follow the existing rule of their source disk (deferred for `public` and `s3`, immediate for `local`).
- [ ] **Step 2:** Run; expect failures.
- [ ] **Step 3:** Implement. `moveLocked()` resolves three (disk, path) pairs instead of one target disk; insurance and cleanup act per disk. The page and command label `public` as "This server".
- [ ] **Step 4:** Run the move tests and `CloudDiskFlowsTest`; then `composer test`.
- [ ] **Step 5:** Commit.

### Task 9: Web server caching and documentation

**Files:**
- Modify: `public/.htaccess`, `README.md`, `docs/specs/media.md`, `docs/specs/site-settings.md`, `CLAUDE.md` (decision 52; decision 41 marked superseded for raster images on this server; "Starter kit structure" notes), the design doc's status line
- Test: none (configuration and documentation)

- [ ] **Step 1:** `public/.htaccess`: inside `IfModule mod_headers.c`, `Cache-Control "public, max-age=604800"` for image extensions under `storage/media/`. README: install step `php artisan storage:link`, the nginx `location ^~ /storage/media/` snippet with the same header, and what the admin shows when the link is missing.
- [ ] **Step 2:** Update the specs (routes table gains `media.static` and `admin.media.direct-check`; "Storage disks"; move section; Media tab) and CLAUDE.md.
- [ ] **Step 3:** `composer test`; run the suite against MySQL as the earlier parts did.
- [ ] **Step 4:** Commit.

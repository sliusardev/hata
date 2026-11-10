# Media Library, Part 1: Foundation and Library Page — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Upload, optimize, store, serve and manage media files (folders, grid/list, details, delete), plus the "Media" tab of the site settings.

**Architecture:** Eloquent models `Media` / `MediaFolder` / `MediaUsage`. Actions in `app/Actions/Media` take `User $actor` and authorize through `MediaPolicy`. Image work goes through Laravel's `Image` facade, wrapped in one `OptimizeImage` service. Files are always reached through `Storage::disk($media->disk)` and served by `MediaController` at `/media/{key}/{name}`. The library is one Livewire SFC page with an Alpine uploader that resizes in the browser and uploads one file at a time.

**Tech Stack:** Laravel 13 (`Illuminate\Image`), `intervention/image` ^4.2, Livewire 4 SFC, Flux free, Alpine, Pest 5.

**Spec:** `docs/specs/media.md` (approved 2026-11-10). Mockup: https://claude.ai/artifact/4ht3hKQ37R8oawQZFBCoi4

**Not in this plan (later plans, same spec):** Part 2 crop / replace / restore original (Cropper.js). Part 3 "Optimize existing" page and `media:optimize`. Part 4 `<livewire:media-picker>`. Part 5 S3 settings and moving files between disks.

## Global Constraints

- PHP `^8.4`, Laravel 13.40, Livewire 4.4 SFC (`resources/views/pages/**/⚡name.blade.php`), Flux **free** only (no `flux:select variant="listbox"`, no `flux:editor`).
- New dependency: `intervention/image:^4.2` only. No other packages in this plan.
- All media file access goes through `Storage::disk($media->disk)`. No `storage_path()` and no local paths in media code (spec "Storage disks").
- Translations: PHP files only, every key in both `lang/en` and `lang/uk` in the same change, snake_case nested keys. Admin UI copy goes in `lang/*/media.php`. Artisan output is English (decision 17).
- Code checks permissions (`media.view|upload|edit|delete`), never role names.
- Actions call `Gate::forUser($actor)->authorize(...)` (decision 25). Violations show as a field error or danger toast. Toasts after redirect use `App\Support\Toast`, otherwise `Flux::toast`.
- Every destructive action is confirmed with `<x-confirm-modal>` (user's decision 2026-11-10). Row actions go in `<x-admin.row-actions>` (decision 35).
- Dates: `->tz(config('admin.timezone'))->isoFormat('LL')` (decisions 27, 38).
- Allowed types, limits and settings defaults are exactly as in spec "Upload" and "Site settings → Media tab".
- Storage paths: `media/{Y}/{m}/{key}.{ext}`, `media/originals/{key}.{ext}`, `media/thumbs/{key}.webp`.
- Every task: `vendor/bin/pint --dirty --format agent`, then run the affected tests. The final task runs `composer test`.
- Specs rule (CLAUDE.md): the spec already describes the target. The last task updates its status banner to say which parts are built.

## Review Focus

1. **Unicode / odd file names** (`Фото відпустки (1).JPG`, `.jpeg` upper case, no extension, double extension `x.php.jpg`). Expect a transliterated slug name, the extension from the detected type, and a rejection when extension and content disagree. Pinned in Task 5 (`it slugs cyrillic names…`, `it rejects a php file renamed to jpg`).
2. **The same name uploaded twice, or a name that only differs by extension** (`a.jpg`, then `a.png`, both optimized to `a.webp`). Expect `a.webp` and `a-2.webp`. Pinned in Task 5 (`it keeps names unique after conversion`).
3. **A file deleted from disk by hand, or a missing thumbnail.** Expect a 404 from `media.show` (not 500), a document icon instead of a thumbnail in the grid, and delete still working. Pinned in Task 6 (`it returns 404 when the file is missing`) and Task 7 (`it deletes a media row whose files are already gone`).
4. **A tiny image (16×16) or a PNG that gets bigger as WebP.** Expect the input kept (`optimized_with` set, size unchanged) and no upscaling. Pinned in Task 4 (`it keeps the input when the result is not smaller`, `it never upscales`).
5. **Deleting a folder that holds a folder that holds files, while another tab still shows that folder.** Expect everything deleted, the stale tab falling back to "All files" without an exception. Pinned in Task 7 (`it deletes nested folders and their files`) and Task 9 (`it falls back to all files when the open folder no longer exists`).

---

### Task 1: Dependency, config, permissions

**Files:**
- Modify: `composer.json` / `composer.lock` (`composer require intervention/image:^4.2 --no-interaction`)
- Create: `config/media.php`
- Modify: `app/Providers/AppServiceProvider.php` (image driver, Livewire upload rule)
- Modify: `config/permissions.php` (group `media`, `editor` defaults)
- Modify: `lang/en/permissions.php`, `lang/uk/permissions.php`
- Modify: `CLAUDE.md` (Specs list: add the media spec; Stack table: intervention/image)
- Add: `docs/specs/media.md` and this plan (written before the code, committed with this task)
- Test: `tests/Feature/Media/MediaConfigTest.php`

**Interfaces:**
- Produces: `config('media.disk')` (env `MEDIA_DISK`, default `local`).
- Produces: `config('media.optimize.{enabled,max_side,quality,format,strip_exif,client_resize,keep_originals}')` = `true, 2560, 80, 'webp', true, true, true`.
- Produces: `config('media.limits.{image_mb,document_mb}')` = `30, 20`.
- Produces: `config('media.thumb_side')` = `400` and `config('media.client_side')` = `3840`.
- Produces: `config('media.grid_per_page')` = `40` and `config('media.list_per_page')` = `25`.
- Produces: permissions `media.view`, `media.upload`, `media.edit`, `media.delete`.

- [ ] **Step 1: Write failing tests**

```php
it('uses imagick when the extension is loaded, otherwise gd', function () {
    expect(config('images.default'))->toBe(extension_loaded('imagick') ? 'imagick' : 'gd');
});

it('registers the media permissions and gives them to editors', function () {
    seedRolesAndPermissions();
    expect(Role::findByName('editor')->permissions->pluck('name')->all())
        ->toContain('media.view', 'media.upload', 'media.edit', 'media.delete');
});

it('raises the livewire upload limit to the largest media limit', function () {
    expect(config('livewire.temporary_file_upload.rules'))->toBe(['required', 'file', 'max:30720']);
});
```

- [ ] **Step 2: Run and confirm they fail.** `php artisan test --compact tests/Feature/Media/MediaConfigTest.php`. Expected: 3 failures.
- [ ] **Step 3: Implement.**
  - Install the package.
  - Write `config/media.php` with the values above, documented like `config/admin.php`.
  - In `AppServiceProvider::boot()`, after `SiteSettings::apply()`, set `images.default` and set the Livewire temporary upload rules to `max:` = `max(image_mb, document_mb) * 1024`.
  - Add `'media' => ['view', 'upload', 'edit', 'delete']` to the groups and the four permissions to `editor`.
  - Add the labels: group "Media library" / «Медіатека», items "View the media library", "Upload files", "Edit files and folders", "Delete files and folders" plus Ukrainian equivalents.
- [ ] **Step 4: Run the tests plus `tests/Feature/Localization/TranslationParityTest.php` and `tests/Feature/Database/RoleSeederTest.php`.** Expected: PASS.
- [ ] **Step 5: Commit** `Add media config, permissions and intervention/image`.

---

### Task 2: Tables, models, policy, factories

**Files:**
- Create: migrations `create_media_folders_table`, `create_media_table`, `create_media_usages_table` (`php artisan make:migration`)
- Create: `app/Models/Media.php`, `app/Models/MediaFolder.php`, `app/Models/MediaUsage.php`
- Create: `app/Enums/MediaKind.php` (`Image`, `Document`, `Other`; backed `string`: `image`, `document`, `other`)
- Create: `app/Policies/MediaPolicy.php`
- Create: `database/factories/MediaFactory.php`, `database/factories/MediaFolderFactory.php`
- Test: `tests/Feature/Media/MediaModelTest.php`

**Interfaces:**
- Produces: columns exactly as spec "Data", plus:
  - `media.kind` (string, `MediaKind`) for filtering;
  - unique index on `media.name` and on `media.key`;
  - index on `media.created_at`.
- Produces: `Media` casts: `alt` → `array`, `edits` → `array`, `kind` → `MediaKind`.
- Produces: `Media` methods:
  - `folder(): BelongsTo`, `uploader(): BelongsTo`, `usages(): HasMany`;
  - `url(): string` = `route('media.show', [$this->key, $this->name])`;
  - `thumbUrl(): ?string` = the same with `?thumb=1`, `null` without `thumb_path`;
  - `isImage(): bool`;
  - `altFor(string $locale): string` (empty string when missing);
  - `savedBytes(): ?int` (`original_size - size` when `original_size` is larger).
- Produces: `Media::creating` fills `key` with `(string) Str::ulid()`.
- Produces: `Media` uses `HasSearchIndex`; source = `name + title + implode(' ', alt)`.
- Produces: `MediaFolder` methods:
  - `parent(): BelongsTo`, `children(): HasMany`, `media(): HasMany`;
  - `static tree(): Collection` (all folders ordered by name, with `media_count`);
  - `descendantIds(): array<int>` (self excluded, computed in PHP from one query);
  - `breadcrumb(): Collection` (root first, self last).
- Produces: `MediaUsage::sync(Model $usable, string $field, array $mediaIds): void`.
- Produces: `MediaPolicy`:
  - `viewAny` → `media.view`, `create` → `media.upload`, `update` → `media.edit`, `delete` → `media.delete`;
  - `manageFolders(User)` → `media.edit`;
  - `deleteFolders(User)` → `media.edit && media.delete`.
- Produces: factory states `image(int $w = 800, int $h = 600)`, `document()`, `inFolder(MediaFolder $f)`. Factories write no files; tests that need files use `Storage::fake` and the helpers from Task 3.

- [ ] **Step 1: Failing tests:**
  - `it generates a ulid key on create` (26 chars, unique across two rows);
  - `it builds the public url from key and name` (`/media/{key}/hero.webp`);
  - `it indexes name, title and every alt for search` (`Media::search('дніпро')` finds a row whose `alt.uk` is `Осінній Дніпро`);
  - `it lists folder descendants at any depth` (a → b → c: `a->descendantIds()` = `[b, c]`);
  - `it syncs usages` (sync `[1, 2]`, then `[2]` leaves one row);
  - `it maps media abilities to permissions` (user `withPermissions(['media.view'])` can `viewAny`, cannot `delete`).
- [ ] **Step 2: Run, confirm fail.**
- [ ] **Step 3: Implement** the migrations, models, enum, policy and factories as listed. `uploaded_by` uses `nullOnDelete`. `folder_id` uses `nullOnDelete` at the DB level; folder deletion itself is done in code (Task 7).
- [ ] **Step 4: Run, confirm pass.** `php artisan test --compact tests/Feature/Media/MediaModelTest.php`.
- [ ] **Step 5: Commit** `Add media models and tables`.

---

### Task 3: File types and test image helpers

**Files:**
- Create: `app/Support/Media/MediaTypes.php`
- Modify: `tests/Pest.php` (helpers)
- Test: `tests/Feature/Media/MediaTypesTest.php`

**Interfaces:**
- Produces: `MediaTypes::ALLOWED`, a `const array<string ext, array{mimes: list<string>, kind: MediaKind}>` covering exactly the spec list:
  - images: jpg/jpeg, png, webp, gif, svg, avif, heic, heif;
  - documents: pdf, docx, xlsx, pptx, doc, xls, ppt, odt, ods, odp, txt, csv, rtf;
  - other: zip, mp3.
- Produces: `MediaTypes::detect(UploadedFile $file): ?array{ext: string, mime: string, kind: MediaKind}`. Returns `null` when the file's real MIME (`$file->getMimeType()`) doesn't belong to any allowed type or doesn't match its extension's type. `jpeg` normalizes to `jpg`.
- Produces: `MediaTypes::limitBytes(MediaKind $kind): int` (image → `media.limits.image_mb`, others → `document_mb`).
- Produces: `MediaTypes::disposition(string $mime): 'inline'|'attachment'` (images, PDF, MP3 inline; the rest attachment).
- Produces: `MediaTypes::responseMime(string $mime): string` (`text/csv` and `text/plain` → `text/plain; charset=UTF-8`, others unchanged).
- Produces test helpers in `tests/Pest.php`:
  - `makeImage(string $format = 'jpg', int $width = 800, int $height = 600): string` (bytes from GD; `format` ∈ jpg, png, webp, gif);
  - `withExif(string $jpeg): string`: inserts an APP1 segment right after the `FFD8` SOI marker: `FFE1`, 2-byte big-endian length, `"Exif\0\0"`, then a minimal little-endian TIFF header `"II*\0\x08\0\0\0\0\0"`;
  - `animatedGif(): string` (two GCE blocks; a hand-built 2-frame GIF);
  - `uploadedImage(string $name, string $bytes): UploadedFile` (`UploadedFile::fake()->createWithContent`).

- [ ] **Step 1: Failing tests:**
  - `it detects allowed files by content` (dataset: `photo.jpg` JPEG bytes → `jpg/image`; `doc.pdf` with `%PDF-1.4` → `pdf/document`; `song.mp3` with an ID3 header → `mp3/other`);
  - `it rejects a php file renamed to jpg` (`<?php echo 1;` as `x.jpg` → `null`);
  - `it rejects unknown types` (`design.psd` → `null`);
  - `it serves text as plain text attachments` (`responseMime('text/csv')` = `text/plain; charset=UTF-8`, `disposition('text/csv')` = `attachment`, `disposition('application/pdf')` = `inline`).
- [ ] **Step 2: Run, confirm fail.**
- [ ] **Step 3: Implement** `MediaTypes` and the helpers.
- [ ] **Step 4: Run, confirm pass.**
- [ ] **Step 5: Commit** `Add the media type whitelist`.

---

### Task 4: `OptimizeImage` service

**Files:**
- Create: `app/Actions/Media/OptimizeImage.php`
- Create: `app/Support/Media/OptimizedImage.php` (readonly DTO)
- Create: `app/Support/Media/MemoryGuard.php`
- Test: `tests/Feature/Actions/Media/OptimizeImageTest.php`

**Interfaces:**
- Produces: `OptimizedImage` (readonly):
  - `string $contents`, `string $mime`, `string $extension`, `?int $width`, `?int $height`;
  - `bool $optimized`; `?string $fingerprint` (`null` when not optimized);
  - `?string $failure`: `null`, `'too_large'` or `'unreadable'`.
- Produces: `OptimizeImage::handle(string $contents, string $mime, ?array $edits = null): OptimizedImage`. `$edits` is accepted and ignored until Part 2.
- Produces: `OptimizeImage::thumbnail(string $contents): ?string` (WebP, long side `media.thumb_side`, `null` on failure or when the guard refuses).
- Produces: `OptimizeImage::fingerprint(): string` = `"{format}:{max_side|none}:{quality}:exif{0|1}"`, e.g. `webp:2560:80:exif0` (exif0 = stripped).
- Produces: `MemoryGuard::canDecode(int $width, int $height): bool`. Estimate `width * height * 4 * 2.2`, compared with `memory_limit` minus `memory_get_usage(true)`; `memory_limit = -1` → always `true`. The constructor takes `?int $limitBytes = null` so tests can bind a small limit.

Rules (from the spec):
- Never re-encode SVG, animated GIF, or anything with `media.optimize.enabled = false`. Those return `optimized = false`, `failure = null`, the input unchanged.
- Read dimensions with `getimagesizefromstring` (header only). Unknown dimensions → `failure = 'unreadable'`, input unchanged.
- Guard refuses → `failure = 'too_large'`, input unchanged.
- Pipeline: `Image::fromBytes($contents)->orient()`, `->scale($maxSide, $maxSide)` only when larger (never upscale), then `->optimize('webp', $quality)` or keep the format at `->quality($quality)` when `format = original`.
- EXIF must be absent from the output when `strip_exif` is on (GD drops it; under Imagick, if the output still carries metadata, strip it through `Image::transformUsing()`).
- If the output is not smaller than the input, return the input with `optimized = true` and the fingerprint (it's already optimal).
- Any `ImageException` → `failure = 'unreadable'`.

- [ ] **Step 1: Failing tests (use `config([...])` to set options):**
  - `it scales a large jpeg down to the max side and converts it to webp` (`makeImage('jpg', 3000, 2000)` → mime `image/webp`, width `2560`, height `1707`, `optimized` true, fingerprint `webp:2560:80:exif0`);
  - `it never upscales` (`400×300` stays `400×300`);
  - `it keeps the input when the result is not smaller` (a 16×16 PNG: contents identical to input, `optimized` true);
  - `it strips exif` (`withExif(makeImage('jpg', 1200, 800))` → output does not contain `"Exif"`);
  - `it keeps the original format when asked` (`format = original`, JPEG in → `image/jpeg` out);
  - `it leaves svg and animated gif untouched` (`optimized` false, same bytes);
  - `it refuses images that would not fit in memory` (bind `MemoryGuard` with `limitBytes: 1024 * 1024`, `makeImage('jpg', 2000, 2000)` → `failure` `too_large`, same bytes);
  - `it reports unreadable images` (`"\xFF\xD8garbage"` → `failure` `unreadable`);
  - `it makes a 400px webp thumbnail` (3000×2000 → `getimagesizefromstring` gives `400×267`, mime webp).
- [ ] **Step 2: Run, confirm fail.**
- [ ] **Step 3: Implement** the three classes.
- [ ] **Step 4: Run, confirm pass.** Run once with `config(['images.default' => 'gd'])` forced if Imagick is the local default, to be sure both drivers pass.
- [ ] **Step 5: Commit** `Add image optimization`.

---

### Task 5: `UploadMedia` action

**Files:**
- Create: `app/Actions/Media/UploadMedia.php`
- Create: `app/Support/Media/MediaNames.php`
- Create: `app/Exceptions/MediaUploadException.php` (translated message, like `ProtectedActionException`)
- Create: `lang/en/media.php`, `lang/uk/media.php` (start with `errors.*`; later tasks add to these files)
- Test: `tests/Feature/Actions/Media/UploadMediaTest.php`

**Interfaces:**
- Consumes: `MediaTypes::detect`, `MediaTypes::limitBytes`, `OptimizeImage::handle/thumbnail`, `Media`, `MediaPolicy::create`.
- Produces: `UploadMedia::handle(User $actor, UploadedFile $file, ?MediaFolder $folder = null, ?int $pickedSize = null): Media`.
- Produces: `MediaNames::unique(string $originalName, string $extension, ?int $ignoreId = null): string`:
  - `Str::slug(pathinfo name)` (fallback `file`);
  - then `.{extension}`;
  - then `-2`, `-3`… until no other `media.name` matches.
- Produces: `MediaUploadException` with keys `media.errors.unsupported` (`:name`), `media.errors.too_large` (`:size`, `:limit`, in MB with one decimal), `media.errors.heic_unsupported`.

Behavior:
1. Authorize `create`.
2. Detect the type, or throw `unsupported`.
3. Check the size limit, using `max($pickedSize ?? 0, $file->getSize())`, or throw `too_large`.
4. HEIC/HEIF that the optimizer reports `unreadable` → `heic_unsupported`.
5. Keep the original (when `keep_originals`, images only) at `media/originals/{key}.{ext}`. Store the optimized result at `media/{Y}/{m}/{key}.{resultExt}` and the thumbnail at `media/thumbs/{key}.webp`.
6. Fill the row: `name` uses the result extension, `original_size = $pickedSize ?? $file->getSize()`, `optimized_with` = the fingerprint or `null`, `width`, `height`, `kind`, `uploaded_by`, `disk = config('media.disk')`.
7. Files are written first. If the DB insert fails, they are deleted.

- [ ] **Step 1: Failing tests (`Storage::fake('local')`, `seedRolesAndPermissions()`, actor `withPermissions(['admin.access', 'media.upload'])`):**
  - `it uploads and optimizes a photo` (3000×2000 JPEG named `Фото відпустки (1).JPG`, `pickedSize: 18_400_000` → `name` `foto-vidpustky-1.webp`, `original_size` 18400000, the file, original and thumbnail exist on the disk, `optimized_with` `webp:2560:80:exif0`);
  - `it slugs cyrillic names and falls back to file` (`.jpg` with empty base → `file.webp`);
  - `it keeps names unique after conversion` (`a.jpg` then `a.png` → `a.webp`, `a-2.webp`);
  - `it rejects a php file renamed to jpg` (throws `MediaUploadException`, message = `__('media.errors.unsupported', ['name' => 'x.jpg'])`, nothing stored);
  - `it enforces the size limit using the picked size` (`media.limits.image_mb = 1`, `pickedSize: 2_000_000` → throws, message mentions `2.0` and `1`);
  - `it stores an image the server cannot process as it is` (guard with a tiny limit → row exists, `optimized_with` null, bytes unchanged, no thumbnail);
  - `it stores documents without processing` (PDF → `kind` document, no thumbnail, no original);
  - `it does not keep originals when the setting is off`;
  - `it puts the file in the given folder`;
  - `it requires the upload permission` (actor without `media.upload` → `AuthorizationException`).
- [ ] **Step 2: Run, confirm fail.**
- [ ] **Step 3: Implement.**
- [ ] **Step 4: Run, confirm pass.** Also run the translation parity test.
- [ ] **Step 5: Commit** `Add media uploads`.

---

### Task 6: Public serving (`MediaController`)

**Files:**
- Create: `app/Http/Controllers/MediaController.php`
- Modify: `routes/web.php` (`GET /media/{key}/{name}` → `media.show`, `where('key', '[0-9A-HJKMNP-TZ]{26}')`)
- Test: `tests/Feature/Media/MediaControllerTest.php`

**Interfaces:**
- Consumes: `Media` (lookup by `key`), `MediaTypes::disposition/responseMime`.
- Produces: `MediaController::__invoke(Request $request, string $key, string $name): Response`.

Behavior:
- Unknown key → 404. `{name}` ≠ the current `name` → 301 to `$media->url()` (keeps `?thumb=1`).
- `?thumb=1` serves `thumb_path` (404 when null).
- A file missing on the disk → 404.
- Headers:
  - `Cache-Control: public, max-age=604800`;
  - `ETag` = `"` + `md5(path . size . updated_at)` + `"`;
  - `Last-Modified`;
  - `X-Content-Type-Options: nosniff`;
  - `Content-Disposition` from `disposition()` with the current `name`;
  - SVG also gets the logo's CSP: `default-src 'none'; style-src 'unsafe-inline'; sandbox`.
- `If-None-Match` matching the ETag → 304 with no body.
- Cloud disks (anything not `local`) redirect 302 to `Storage::disk()->url()`. This stays a stub for Part 5, covered by one test with a fake disk configured with a `url`.

- [ ] **Step 1: Failing tests:**
  - `it serves an image inline with cache headers`;
  - `it returns 304 when the etag matches`;
  - `it redirects an old name to the current one` (`/media/{key}/hero.jpg` → 301 `/media/{key}/hero.webp`);
  - `it sends svg with a sandbox csp`;
  - `it sends csv as a plain text attachment`;
  - `it serves the thumbnail`;
  - `it returns 404 for an unknown key and when the file is missing`;
  - `it needs no login` (guest request → 200).
- [ ] **Step 2: Run, confirm fail.**
- [ ] **Step 3: Implement.**
- [ ] **Step 4: Run, confirm pass.**
- [ ] **Step 5: Commit** `Serve media files`.

---

### Task 7: Edit, move, delete; folder actions

**Files:**
- Create in `app/Actions/Media`: `UpdateMedia.php`, `MoveMedia.php`, `DeleteMedia.php`, `CreateFolder.php`, `RenameFolder.php`, `DeleteFolder.php`
- Test: `tests/Feature/Actions/Media/ManageMediaTest.php`, `tests/Feature/Actions/Media/FolderActionsTest.php`

**Interfaces:**
- Produces: `UpdateMedia::handle(User $actor, Media $media, array{title?: ?string, alt?: array<string, string>, folder_id?: ?int} $data): Media`. Alt is limited to `available_locales` keys; each alt ≤ 500 chars; title ≤ 255.
- Produces: `MoveMedia::handle(User $actor, iterable<Media>|Media $media, ?MediaFolder $folder): void`.
- Produces: `DeleteMedia::handle(User $actor, iterable<Media>|Media $media): int` (count deleted).
  - Rows are deleted in a transaction; `path`, `original_path` and `thumb_path` are deleted from each row's disk in `DB::afterCommit`.
  - Missing files are ignored.
- Produces: `CreateFolder::handle(User $actor, string $name, ?MediaFolder $parent): MediaFolder`. The name is trimmed, 1–100 chars, unique among siblings. Error keys: `media.errors.folder_exists` and `media.errors.folder_name`.
- Produces: `RenameFolder::handle(User $actor, MediaFolder $folder, string $name): MediaFolder` (same rules).
- Produces: `DeleteFolder::handle(User $actor, MediaFolder $folder): array{folders: int, files: int}`.
  - Authorizes `deleteFolders`.
  - Collects `descendantIds()` plus self.
  - Deletes all their media through `DeleteMedia` (without re-authorizing per file), then the folders, deepest first, in one transaction.
- Produces: `MediaFolder::deletionSummary(): array{folders: int, files: int, used: int}` (for the confirmation dialog).

- [ ] **Step 1: Failing tests:**
  - `it updates title, alt and folder`;
  - `it ignores alt for locales that are not available`;
  - `it moves several files to a folder and back to no folder`;
  - `it deletes files and their stored files after commit` (all three paths gone);
  - `it deletes a media row whose files are already gone`;
  - `it requires media.delete to delete`;
  - `it creates nested folders and refuses a duplicate sibling name` (same name under another parent is fine);
  - `it renames a folder`;
  - `it deletes nested folders and their files` (a → b → c with 1 + 2 + 3 files → returns `['folders' => 3, 'files' => 6]`, nothing left in either table or on the disk);
  - `it summarizes what a folder deletion removes` (`used` counts files with a `media_usages` row);
  - `it requires both media.edit and media.delete to delete a folder`.
- [ ] **Step 2: Run, confirm fail.**
- [ ] **Step 3: Implement.**
- [ ] **Step 4: Run, confirm pass.**
- [ ] **Step 5: Commit** `Add media and folder actions`.

---

### Task 8: Site settings → "Media" tab

**Files:**
- Modify: `app/Support/Settings/SiteSettings.php` (`CONFIG_PATHS` for the nine `media_*` keys in the spec table)
- Modify: `app/Livewire/Forms/SiteSettingsForm.php` (properties, `fillFromSettings`, `SECTIONS['media']`, rules)
- Modify: `resources/views/pages/admin/settings/⚡edit.blade.php` (sixth tab, `?tab=media`)
- Modify: `lang/*/site_settings.php`
- Modify: `docs/specs/site-settings.md` (tab list, settings table: add the media rows)
- Test: `tests/Feature/Admin/Settings/MediaSettingsTest.php`

**Interfaces:**
- Consumes: `config/media.php` keys from Task 1.
- Produces: form fields with the same names as the spec setting keys:
  - `media_optimize` (bool);
  - `media_max_side` (`'1920'|'2560'|'3840'|'none'`; stored as int, `none` stored as `null`);
  - `media_quality` (int 50–100), `media_format` (`webp|original`);
  - `media_strip_exif`, `media_client_resize`, `media_keep_originals` (bool);
  - `media_max_image_mb`, `media_max_document_mb` (int 1–512).

UI (screen 7 of the mockup, without the "Already uploaded files" card, which belongs to Part 3):
- `flux:switch` for the booleans, native `flux:select` for max side, `<input type="range">` with the value shown for quality, `flux:radio.group` for format.
- AVIF is listed disabled with "not supported by the server" when the driver can't encode AVIF (check by encoding a 1×1 image once; remember the result in `Cache::rememberForever('media.avif_supported')`).
- Under the limits: "The server accepts files up to :size" from `min(upload_max_filesize, post_max_size)` parsed with `ini_parse_quantity`.
- Saving a limit higher than the server cap shows a field error (`media.errors.limit_above_server`, with `:size`).

- [ ] **Step 1: Failing tests:**
  - `it shows the media tab` (`?tab=media` renders the quality value `80`);
  - `it saves the media settings and applies them to config` (after save, `config('media.optimize.quality')` = 70, `config('media.optimize.max_side')` = null for `none`);
  - `it refuses a limit above the server cap`;
  - `it keeps other tabs untouched` (saving media doesn't store `site_name`).
- [ ] **Step 2: Run, confirm fail.**
- [ ] **Step 3: Implement.**
- [ ] **Step 4: Run, confirm pass**, plus `tests/Feature/Admin/Settings` and `tests/Feature/Support/Settings`.
- [ ] **Step 5: Commit** `Add the media tab to site settings`.

---

### Task 9: Library page — browsing (grid, list, folders, filters)

**Files:**
- Modify: `routes/admin.php` (`Route::livewire('media', 'pages::admin.media.index')->name('media.index')`)
- Create: `resources/views/pages/admin/media/⚡index.blade.php`
- Create: `resources/views/partials/admin/media/folder-tree.blade.php`, `grid.blade.php`, `list.blade.php`
- Modify: `resources/views/layouts/app/sidebar.blade.php` (group `navigation.content` with item `navigation.media`, icon `photo`, `@can('media.view')`)
- Modify: `lang/*/navigation.php`, `lang/*/media.php`
- Test: `tests/Feature/Admin/Media/MediaIndexTest.php`

**Interfaces:**
- Consumes: `Media`, `MediaFolder::tree()/breadcrumb()`, `MediaPolicy`.
- Produces: component properties with `#[Url]`:
  - `?string $folder` (`null` = all, `'none'` = no folder, otherwise an id);
  - `string $search`, `string $type` (`''|image|document|other`);
  - `string $month` (`''` or `Y-m`), `bool $unused`;
  - `string $view` (`grid|list`);
  - `string $sortBy` (`created_at|name|size`), `string $sortDirection`.
- Produces: `int $gridLimit = 40` (+40 on `showMore()`); the list uses `paginate(25)`.
- Produces: `?int $selectedId` (details panel, Task 10) and `array<int> $selected` (bulk, Task 11).
- Produces: computed `media()`, `folders()`, `months()`, `currentFolder()`.

Behavior (screens 1 and 4):
- Month options are computed in PHP from `min(created_at)` to now in `admin.timezone`. The filter uses `whereBetween` on that month's UTC range (no DB-specific date SQL).
- `unused` = `doesntHave('usages')`.
- A folder id that no longer exists falls back to "All files" (`$folder = null`).
- Empty states: "No files yet" with an upload hint, and "Nothing found".
- The view choice is also remembered in `localStorage` via Alpine (`x-init` reads it when the URL has no `view`).
- Thumbnails use `thumbUrl()` with `loading="lazy"`. Without a thumbnail, the kind icon and the uppercase extension.
- Tiles of files without usages carry the "unused" badge. In the list, a missing alt (for the current locale) shows "No alt text" in amber, and the usage column shows the amber "Nowhere" badge.

- [ ] **Step 1: Failing tests:**
  - `it shows the library to users with media.view` (200, sees file names);
  - `it is forbidden without media.view` (403);
  - `it shows the content group in the sidebar only with media.view`;
  - `it filters by folder, no folder, type, month, unused and search` (one test per filter, using a dataset);
  - `it falls back to all files when the open folder no longer exists`;
  - `it shows 40 tiles and more on demand` (45 rows → 40, then 45 after `showMore`);
  - `it sorts the list by size` (list view);
  - `it keeps filters in the url` (`Livewire::withQueryParams(['type' => 'document'])`);
  - `it does not run a query per tile` (25 rows; the query count stays the same as with 1 row, ±0, using `DB::enableQueryLog`).
- [ ] **Step 2: Run, confirm fail.**
- [ ] **Step 3: Implement** to match the mockup screens 1 and 4 with Flux components (`flux:input`, `flux:select`, `flux:table`, `flux:button`, `flux:badge`, `flux:dropdown`/`flux:menu`). Surfaces use `bg-sidebar`/zinc/accent utilities (decision 40).
- [ ] **Step 4: Run, confirm pass.**
- [ ] **Step 5: Commit** `Add the media library page`.

---

### Task 10: Details panel, folder management, delete confirmations

**Files:**
- Modify: `resources/views/pages/admin/media/⚡index.blade.php`
- Create: `resources/views/partials/admin/media/details.blade.php`
- Create: `app/Livewire/Forms/MediaDetailsForm.php`
- Modify: `lang/*/media.php`
- Test: `tests/Feature/Admin/Media/MediaDetailsTest.php`, `tests/Feature/Admin/Media/MediaFoldersTest.php`

**Interfaces:**
- Consumes: `UpdateMedia`, `MoveMedia`, `DeleteMedia`, `CreateFolder`, `RenameFolder`, `DeleteFolder`, `MediaFolder::deletionSummary()`.
- Produces: component methods:
  - `select(int $id)`, `saveDetails()`, `confirmDelete(int $id)`, `deleteConfirmed()` (deletes whatever the open confirmation is about: one file or the bulk selection);
  - `createFolder(string $name)`, `renameFolder(int $id, string $name)`;
  - `confirmFolderDelete(int $id)`, `deleteFolder()`;
  - `moveTo(array $ids, ?int $folderId)` (drag-and-drop onto a folder calls this).
- Produces: `MediaDetailsForm` fields `title`, `alt` (array per locale), `folder_id`.

Behavior (screen 1 right panel):
- The panel shows:
  - preview;
  - name;
  - type, "WebP (uploaded as JPG)" when the original's extension differs;
  - size and dimensions;
  - uploaded date and uploader;
  - the green "Optimized: :before → :after (−:percent%)" line when `savedBytes()`;
  - amber "Not optimized: too large for the server to process" when an image has `optimized_with = null`;
  - alt with a locale switch over `available_locales`, title, folder select;
  - "Where it's used" ("Nowhere" for now);
  - the URL with "Copy" (Alpine clipboard, a `Flux::toast` on success);
  - Save, Download (the `url()` with `download` attribute), Delete.
  - The Replace and Crop buttons are not rendered until Part 2.
- Clicking another file with unsaved changes asks through `<x-confirm-modal>` ("Discard changes").
- The delete dialog names the file. If it has usages, it lists them and still allows deleting.
- The folder delete dialog shows `deletionSummary()`. When `files > 10`, the confirm button stays disabled until the folder's exact name is typed.
- Errors from actions show as field errors (folder name) or a danger toast.

- [ ] **Step 1: Failing tests:**
  - `it opens the details of a file`;
  - `it saves alt text per language and the title`;
  - `it asks before deleting and deletes after confirming` (`confirmDelete` sets the modal state, nothing deleted; `deleteConfirmed` deletes);
  - `it hides delete without media.delete`;
  - `it creates, renames and refuses duplicate folders` (error on `folderName`);
  - `it requires typing the folder name when it holds more than 10 files` (`deleteFolder()` with a wrong `confirmName` deletes nothing);
  - `it deletes a folder with everything inside after confirmation`;
  - `it moves files by drop` (`moveTo([...], $folderId)`);
  - `it shows the optimization result` (a row with `original_size` 18400000 and `size` 167936 shows `18.4 MB → 164 KB`; in `uk` the decimal separator is a comma).
- [ ] **Step 2: Run, confirm fail.**
- [ ] **Step 3: Implement.**
- [ ] **Step 4: Run, confirm pass.**
- [ ] **Step 5: Commit** `Add media details and folder management`.

---

### Task 11: Upload UI, browser-side resize, bulk actions, ZIP

**Files:**
- Create: `resources/js/media-uploader.js` (Alpine component `mediaUploader`, imported in `resources/js/app.js`)
- Modify: `resources/views/pages/admin/media/⚡index.blade.php` (`WithFileUploads`, upload property, drop overlay, queue panel, bulk bar)
- Create: `resources/views/partials/admin/media/upload-queue.blade.php`
- Modify: `lang/*/media.php`
- Test: `tests/Feature/Admin/Media/MediaUploadTest.php`, `tests/Feature/Admin/Media/MediaBulkTest.php`

**Interfaces:**
- Consumes: `UploadMedia::handle`, `DeleteMedia`, `MediaUploadException`.
- Produces: component property `?TemporaryUploadedFile $upload`, `?int $uploadPickedSize`.
- Produces: method `storeUpload(): array{ok: bool, id?: int, name?: string, meta?: string, error?: string}`. The JS calls it after `$wire.upload('upload', …)` finishes. It returns the error text instead of throwing, so the queue can show it on the tile.
- Produces: method `confirmBulkDelete()` (opens the same confirmation, then `deleteConfirmed()` from Task 10).
- Produces: method `downloadZip(): StreamedResponse` (selected files; only rendered when `class_exists(ZipArchive::class)`).
- Produces: JS config passed via `x-data="mediaUploader(@js([...]))"`:
  - `clientResize` and `clientSide` (3840);
  - `limits` (bytes per kind) and `accept` (extensions list);
  - the error texts (`too_large`, `unsupported`), translated server-side.

Behavior (screens 2 and 3):
- Dropping files anywhere on the page shows the overlay. Files go into a queue and upload one at a time.
- Before upload, the JS checks the extension and the picked size against the limits, so the error appears on the tile instantly.
- When `clientResize` is on, for JPG/PNG/WebP/HEIC:
  - `createImageBitmap(file, {imageOrientation: 'from-image'})`;
  - if the long side is > 3840, draw to a canvas scaled to 3840;
  - `canvas.toBlob(…, 'image/jpeg', 0.92)` (PNG with alpha stays `image/png`);
  - keep the original name with the new extension;
  - any failure → upload the original file.
- GIF, SVG and non-images are uploaded as picked. `uploadPickedSize` is always the picked file's size.
- Queue panel:
  - rows "Done · compressed :before → :after", ":percent%", "Compressing…" while `storeUpload` runs, and the error text with "Remove";
  - title "Uploaded :done of :total";
  - collapsible.
- New tiles appear first in the grid, in the open folder.
- Bulk bar (list view checkboxes or "Select several" in the grid): count, "Clear selection", "Download ZIP", "Delete selected". Delete is confirmed and states how many of the selected files are used.

- [ ] **Step 1: Failing tests (Livewire, `UploadedFile::fake()->createWithContent`):**
  - `it stores an uploaded file through the component and returns its tile data`;
  - `it returns the error text for a rejected file` (`ok` false, `error` = `__('media.errors.unsupported', ['name' => 'x.psd'])`);
  - `it stores uploads in the open folder`;
  - `it is forbidden to upload without media.upload` (no upload button, `storeUpload` 403);
  - `it bulk deletes after confirmation`;
  - `it downloads the selected files as a zip` (response is a download named `media.zip`; skip when `ZipArchive` is missing).
- [ ] **Step 2: Run, confirm fail.**
- [ ] **Step 3: Implement** the component parts and `media-uploader.js`. Run `npm run build`.
- [ ] **Step 4: Run, confirm pass.** Then manually check in the browser: upload a big phone photo (expect a ~100–300 KB WebP), drop 5 files at once, try a `.psd`. Confirm `browser-logs` is clean.
- [ ] **Step 5: Commit** `Add uploads with browser-side resizing and bulk actions`.

---

### Task 12: Docs and full check

**Files:**
- Modify: `docs/specs/media.md` (banner: "Part 1 built: …; crop, optimize existing, picker, S3: not yet")
- Modify: `CLAUDE.md` (decisions 41–44: media served by route with ULID keys; Laravel `Image` + intervention 4; browser resize to 3840 + server optimize, store-as-is fallback; folder delete removes contents, every delete confirmed)
- Modify: `README.md` (media requirements: GD or Imagick with WebP, `upload_max_filesize`/`post_max_size` advice)

- [ ] **Step 1:** Update the three files.
- [ ] **Step 2:** Run `composer test`. Expected: Pint, PHPStan level 7 and all tests pass.
- [ ] **Step 3:** Run the suite against MySQL as CI does (`DB_CONNECTION=mysql …`). Expected: PASS.
- [ ] **Step 4: Commit** `Document the media library`.

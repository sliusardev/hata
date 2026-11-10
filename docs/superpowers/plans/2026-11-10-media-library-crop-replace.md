# Media Library Part 2 (Crop, Replace, Restore) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let editors crop, rotate and flip images, replace any file with a new one of the same kind, and restore an image to its original. URLs stay the same, and browsers pick up the change at once.

**Architecture:**
- **Version.** Every row gets a `version` that goes up whenever the bytes behind its URL change. `url()`/`thumbUrl()` carry `?v=`, and the serving route caches for long only when `v` matches.
- **Rebuilding.** A single `RebuildMediaFile` service rebuilds a file from source bytes plus optional `edits`. It writes the new file and thumbnail, bumps the version and removes the old files after commit. Crop, restore, replace and (later) Part 3 all use it.
- **Edits.** `OptimizeImage::handle()` applies edits through an `ImageEdits` value object. The crop page is a Livewire SFC with Cropper.js 1.x on a downscaled preview of the source, served by an admin-only route.

**Tech Stack:** Laravel 13, Livewire 4 SFC, Flux free, Alpine, `Illuminate\Image` (intervention/image 4), `cropperjs@1.6.3` (new npm dependency, approved in the spec), Pest 5, PHPStan 7.

**Spec:** `docs/specs/media.md`, sections "Public URLs and serving", "Library screen" (Replace), "Crop (screen 6)", "Actions", "Dependencies". The approved mockup is board "Crop" in https://claude.ai/artifact/4ht3hKQ37R8oawQZFBCoi4. A local copy is at `/private/tmp/claude-501/-Users-vitalii-Herd-hata/51cc126b-1dab-43f1-a066-c80df609f584/scratchpad/media-design/project/Crop.dc.html`, if it still exists.

## Global Constraints

- Flux **free** only. Livewire SFC pages live in `resources/views/pages/**/⚡name.blade.php`.
- New dependency: `cropperjs@1.6.3` only (npm, pinned exact). Image work goes through Laravel's `Image` facade.
- All media file access goes through `Storage::disk($media->disk)`. No `storage_path()` and no local paths in media code.
- Translations: PHP files only, with every key in both `lang/en` and `lang/uk` in the same change, as snake_case nested keys. Admin copy goes in `lang/*/media.php`.
- Code checks permissions, never role names:
  - crop in place, replace and restore: `media.edit`;
  - "Save as copy": `media.upload`;
  - the crop page: `media.edit`.
- Actions take `User $actor` and call `Gate::forUser($actor)->authorize(...)`. Authorization failures show a danger toast, validation errors show as field errors.
- Every destructive change is confirmed with `<x-confirm-modal>`: "Apply" crop, Replace and Restore all change the file everywhere it's used. The dialog names the file and how many places use it.
- Storage paths: `media/{Y}/{m}/{key}.{ext}`, `media/originals/{key}.{ext}`, `media/thumbs/{key}.webp`. `key` and `id` never change.
- Sizes use binary units (`Number::fileSize`), and limits are `mb*1024*1024`.
- `edits` JSON shape (spec): `{crop: {x, y, w, h}, rotate, flip_h, flip_v, size: {w, h}|null}`.
  - `crop` is in pixels of the source **after orientation, rotation and flip**.
  - `rotate` ∈ {0, 90, 180, 270}, clockwise.
- Pint clean, PHPStan level 7 clean, translation parity test green.

## Review Focus

1. **A crop on a file without a kept original** is baked in, so `edits` stays null. A second crop then works on the already-cropped file and must not apply the old edits again. Test: crop twice without an original; the second result has the second crop's size.
2. **Animated GIF/WebP/APNG and SVG** can't be cropped. Crop is hidden for them, and `CropMedia` refuses them with a field error instead of flattening the animation. Test in Task 4.
3. **Replace with a file of another kind** (an image over a PDF) is refused with a field error, and nothing changes on disk. **Replace with another extension** (a PNG over a JPG) changes `name` and `path`. The old `{name}` URL 301s to the new one, and the old file is deleted only after commit. Tests in Task 5.
4. **A failure halfway through a rebuild** (a DB error or exception after the new files are written) leaves the row and its old files exactly as before, and removes the new files. Test in Task 3.
5. **A stale `v` or a missing `v`** on the public URL never caches the old bytes for 7 days. It gets `no-cache` and revalidates with the ETag. Test in Task 1.

---

### Task 1: Version the public URLs

**Files:**
- Create: a migration `add_version_to_media_table` that adds an unsigned integer `version`, default 1, after `edits`.
- Modify: `app/Models/Media.php` (`url()`, `thumbUrl()`, `#[Fillable]`/casts), `database/factories/MediaFactory.php`, `app/Http/Controllers/MediaController.php`.
- Modify: `docs/specs/media.md` ("Public URLs and serving": the `?v=` rule and the cache rules).
- Test: `tests/Feature/Media/MediaControllerTest.php`, `tests/Feature/Media/MediaModelTest.php`.

**Interfaces:**
- Produces:
  - `Media::$version` (int);
  - `url()` → `route('media.show', [key, name, 'v' => version])`;
  - `thumbUrl()` → the same plus `'thumb' => 1`.
- Produces, in MediaController:
  - `Cache-Control: public, max-age=604800` only when `$request->query('v') === (string) $media->version`;
  - otherwise `Cache-Control: no-cache`, with the same ETag/Last-Modified.
  - The ETag already includes the path, size and updated_at. Add `version` to its hash.
  - The name-mismatch 301 keeps `v` and `thumb`.

- [ ] **Step 1: Failing tests.**
  - `it puts the version in the file and thumbnail urls`: version 3 → `url()` ends with `?v=3`; `thumbUrl()` contains both `thumb=1` and `v=3`.
  - `it caches the current version for a week`: `?v=` + version → `max-age=604800`.
  - `it does not cache a stale or missing version`: `?v=1` with version 2, and no `v`, both give `Cache-Control` containing `no-cache` with status 200, and an ETag is still present.
  - `it changes the etag when the version changes`.
  - `it keeps v on the old-name redirect`.
- [ ] **Step 2:** Run, confirm the tests fail.
- [ ] **Step 3:** Implement. The existing tests that build URLs by hand may need `v`. Prefer asserting through `url()`.
- [ ] **Step 4:** Run `tests/Feature/Media` and `tests/Feature/Admin/Media`, confirm they pass.
- [ ] **Step 5:** Commit `Version media URLs so changed files are not served from cache`.

### Task 2: `ImageEdits` and edits in `OptimizeImage`

**Files:**
- Create: `app/Support/Media/ImageEdits.php`.
- Modify: `app/Actions/Media/OptimizeImage.php` (the `$edits` parameter stops being "reserved"), `docs/specs/media.md` (order of operations).
- Test: `tests/Feature/Media/ImageEditsTest.php`, `tests/Feature/Actions/Media/OptimizeImageTest.php`.

**Interfaces:**
- Produces: `final readonly class ImageEdits` with:
  - `public function __construct(public int $rotate = 0, public bool $flipH = false, public bool $flipV = false, public ?array $crop = null /* {x,y,w,h} ints */, public ?array $size = null /* {w,h} ints */)`.
  - `public static function fromArray(?array $edits): ?self`. It returns null for null or empty input. It throws `InvalidArgumentException` for a rotate outside {0, 90, 180, 270}, for non-positive w/h, or for a crop that is not 4 non-negative ints.
  - `public function toArray(): array`, in the spec's JSON shape with snake_case keys.
  - `public function isEmpty(): bool`.
  - `public function apply(\Illuminate\Image\Image $image): \Illuminate\Image\Image`. Order:
    1. rotate (clockwise);
    2. flip H, then flip V;
    3. crop, clamped to the image bounds;
    4. `size`: scale down to fit w×h, never up.
  - `public function outputSize(int $sourceWidth, int $sourceHeight): array{int, int}`. This is pure arithmetic, used for the crop page's "result" line.
- Produces in `OptimizeImage::handle($contents, $mime, ?array $edits)`. When `ImageEdits::fromArray($edits)` is not empty:
  - the image is always decoded and re-encoded, even with optimize off: edits force processing, like `MUST_CONVERT`;
  - the order is orient, then edits, then the usual `max_side` scale (when optimize is on), then encoding;
  - with optimize off the target format is the source format (JPEG/PNG/WebP), at `media.optimize.quality`;
  - the `keepInput` branch is never taken;
  - `width`/`height` in the result are the edited output's;
  - animated and SVG input returns `unchanged()` with failure `'not_editable'`;
  - a memory-guard refusal returns failure `'too_large'`, as now.

- [ ] **Step 1: Failing tests** (generate images with the `makeImage` helper in tests/Pest.php).
  - `ImageEditsTest`:
    - `it round-trips the spec shape`;
    - `it rejects invalid edits` (a dataset of rotate 45, crop w 0, size h -1, crop with a string);
    - `it computes the output size` (4000×3000 with rotate 90 and crop 1000×500 → 1000×500; with size 400×400 → 400×200; with size 2000×2000 → 1000×500, never upscaled).
  - `OptimizeImageTest`:
    - `it rotates clockwise`. Use a 4×2 PNG whose top-left pixel is red and the rest white, with rotate 90 and optimize off. The result is 2×4, and the top-right pixel is red. Read the pixel with GD from the result bytes.
    - `it crops in the rotated frame` (an 800×600 image, rotate 90, crop {x:0,y:0,w:300,h:400} → 300×400);
    - `it applies edits even with optimization off and keeps the format` (JPEG in, JPEG out);
    - `it scales to max side after the crop` (a 4000×1000 crop with max_side 2560 → 2560×640, ±1 px);
    - `it refuses to edit an animated gif` (failure `not_editable`, bytes unchanged).
- [ ] **Step 2:** Run, confirm the tests fail.
- [ ] **Step 3:** Implement. Clockwise direction: check what `Image::rotate()` does with intervention 4. The test above pins it; negate the angle if the driver turns counter-clockwise.
- [ ] **Step 4:** Run, confirm the tests pass. Run the whole `tests/Feature/Actions/Media` too, since the upload path must be unchanged.
- [ ] **Step 5:** Commit `Apply crop, rotate and flip edits when optimizing images`.

### Task 3: `RebuildMediaFile` service

**Files:**
- Create: `app/Actions/Media/RebuildMediaFile.php` (no actor: it is a service like `OptimizeImage`).
- Create: `app/Support/Media/MediaSource.php`.
- Test: `tests/Feature/Actions/Media/RebuildMediaFileTest.php`.

**Interfaces:**
- Produces: `MediaSource::for(Media $media): self`, with `public string $contents`, `public string $mime` and `public bool $isOriginal`.
  - It reads `original_path` when that is set and the file exists, otherwise `path`, from `Storage::disk($media->disk)`.
  - It holds the original's MIME: detect it with `MediaTypes` from the bytes (finfo on the content), because the row's `mime` is the current file's.
- Produces: `RebuildMediaFile::handle(Media $media, string $contents, string $mime, ?array $edits, ?string $newName = null, ?string $newOriginal = null): Media`.
  - It runs `OptimizeImage::handle($contents, $mime, $edits)` for images. Non-images are stored as given.
  - It writes:
    - the new file at `media/{Y}/{m}/{key}.{ext}`, where Y/m is **now** and ext is the result's;
    - the thumbnail, at the same path, overwritten;
    - `$newOriginal`, when given, at `media/originals/{key}.{origExt}`.
  - Inside `DB::transaction` it updates `path`, `mime`, `size`, `width`, `height`, `optimized_with`, `optimize_failure` and `edits`, sets `name` to `$newName ?? MediaNames::unique(pathinfo current name, new ext, ignoreId: id)` when the extension changed, and increments `version`.
  - The previous `path`, and the previous original when it was replaced, are deleted in `DB::afterCommit` only when they differ from the new paths.
  - On any exception, the newly written files are deleted, except paths that were shared with the old ones, and the exception is rethrown.
  - It refuses an image that comes back with a `MUST_CONVERT` mime by throwing `MediaUploadException::heicUnsupported()`, as UploadMedia does.

- [ ] **Step 1: Failing tests** (use `Storage::fake('local')` and the `mediaWithFiles()` helper, or build rows with real image bytes).
  - `it rebuilds an image from its source and bumps the version`;
  - `it writes the new file under this month and deletes the old path after commit`;
  - `it keeps the row and the old files when saving fails`. Register `Media::updating(fn () => throw new RuntimeException('boom'))`, then assert the row is unchanged, the old files exist, and no new file is left.
  - `it renames the file when the extension changes` (`hero.jpg` → `hero.webp`; with a taken name → `hero-2.webp`);
  - `MediaSource`: `it reads the original when one is kept`, and `it falls back to the current file`.
- [ ] **Step 2:** Run, confirm the tests fail.
- [ ] **Step 3:** Implement.
- [ ] **Step 4:** Run, confirm the tests pass.
- [ ] **Step 5:** Commit `Add a service that rebuilds a media file from its source`.

### Task 4: `CropMedia` and `RestoreOriginal`

**Files:**
- Create: `app/Actions/Media/CropMedia.php`, `app/Actions/Media/RestoreOriginal.php`.
- Modify: `lang/*/media.php` (`errors.not_editable`, `errors.no_original`, `errors.too_large_to_edit`).
- Test: `tests/Feature/Actions/Media/CropMediaTest.php`, `tests/Feature/Actions/Media/RestoreOriginalTest.php`.

**Interfaces:**
- Consumes: `MediaSource`, `RebuildMediaFile`, `ImageEdits`, `UploadMedia` (for the copy path: reuse its storing logic, or `RebuildMediaFile` on a new row, whichever avoids duplicating code).
- Produces: `CropMedia::handle(User $actor, Media $media, array $input, bool $asCopy = false): Media`.
  - `$input` = `{crop: {x, y, w, h} as fractions 0–1 of the rotated+flipped source, rotate, flip_h, flip_v, size: {w,h}|null}`.
  - It authorizes `update` on `$media`, or `create` when `$asCopy`.
  - It validates with `ValidationException` field keys `crop`, `rotate` and `size`. The fractions must be 0 ≤ x < 1, 0 < w ≤ 1 − x, and the same for y/h.
  - It refuses a non-image, SVG or animated file with `crop` → `media.errors.not_editable`.
  - It converts the fractions to pixels of the oriented+rotated source. Get the oriented size from `MediaSource` with `Image::fromBytes()->orient()` dimensions, swapping w/h for 90/270.
  - In place, with an original kept: it stores the edits and rebuilds from the original.
  - In place, without an original: it rebuilds from the current file with the edits, then stores `edits = null` (baked in).
  - `asCopy`: it creates a new `Media` in the same folder, uploaded by the actor, with the name `MediaNames::unique(name)`. It gets its own original copy when one was kept, and the edits, under the same rules. The source row is untouched.
  - An optimizer failure `too_large` → `crop` field error `media.errors.too_large_to_edit`.
- Produces: `RestoreOriginal::handle(User $actor, Media $media): Media`. It authorizes `update`. Without an `original_path` file it fails with field `original` → `media.errors.no_original`. Otherwise it rebuilds from the original with `edits = null`.

- [ ] **Step 1: Failing tests.**
  - `it crops from the kept original and stores the edits` (a 3000×2000 original, crop fractions {0, 0, 0.5, 0.5} → edits crop {0, 0, 1500, 1000}; the file is 1500×1000, version +1);
  - `it replaces earlier edits instead of stacking them`. With an original kept, the page always shows and edits the original, so its fractions are fractions of the original. Crop {0, 0, 0.5, 0.5}, then crop {0.5, 0.5, 0.5, 0.5}. The stored edits are the second crop only, {1500, 1000, 1500, 1000}, and the file is 1500×1000 cut from the original's bottom-right, not a crop of the first result.
  - `it bakes the crop in when no original is kept` (`edits` null, and a second crop works on the cropped file), per Review Focus 1;
  - `it refuses to crop an animated gif or an svg`, per Review Focus 2;
  - `it rejects fractions outside the image` (a dataset);
  - `it saves a cropped copy in the same folder and leaves the source alone` (it needs `media.upload`, and a user with only `media.edit` gets an `AuthorizationException`);
  - `it requires media.edit to crop in place`;
  - `it restores the original and clears the edits`;
  - `it refuses to restore without an original`.
- [ ] **Step 2:** Run, confirm the tests fail.
- [ ] **Step 3:** Implement.
- [ ] **Step 4:** Run, confirm the tests pass.
- [ ] **Step 5:** Commit `Add crop and restore-original actions`.

### Task 5: `ReplaceMediaFile`

**Files:**
- Create: `app/Actions/Media/ReplaceMediaFile.php`.
- Modify: `lang/*/media.php` (`errors.other_kind`, with `:kind`).
- Test: `tests/Feature/Actions/Media/ReplaceMediaFileTest.php`.

**Interfaces:**
- Produces: `ReplaceMediaFile::handle(User $actor, Media $media, UploadedFile $file, ?int $pickedSize = null, ?string $pickedExtension = null): Media`.
  - It authorizes `update`.
  - It checks the type and size limits exactly as UploadMedia does. Extract the shared checks into a private trait or a small `MediaUploadChecks` support class instead of copying them.
  - Another `kind` → `MediaUploadException` (`media.errors.other_kind`).
  - It keeps the new file as the original when originals are kept (images only, optimize on, as on upload). Otherwise it drops the old original.
  - It sets `edits` to null.
  - It sets `name` = `MediaNames::unique($file->getClientOriginalName(), resultExt, ignoreId: $media->id)`, and also updates `original_size` and `uploaded_extension`.
  - It goes through `RebuildMediaFile`.
  - `folder_id`, `title`, `alt`, `uploaded_by` and the usages stay.

- [ ] **Step 1: Failing tests.**
  - `it replaces an image and keeps id, key and alt` (the version goes up, and the old file is deleted after commit);
  - `it renames and redirects the old name when the extension changes` (the old name → 301 to the new URL, via the route test helper);
  - `it refuses a file of another kind and changes nothing`, per Review Focus 3;
  - `it replaces a document without processing`;
  - `it applies the size limit with the picked size`;
  - `it requires media.edit`.
- [ ] **Step 2:** Run, confirm the tests fail.
- [ ] **Step 3:** Implement.
- [ ] **Step 4:** Run `tests/Feature/Actions/Media`, confirm it passes. The UploadMedia tests must still pass after the extraction.
- [ ] **Step 5:** Commit `Add replacing a media file`.

### Task 6: Source preview route for the crop page

**Files:**
- Create: `app/Http/Controllers/MediaSourcePreviewController.php`.
- Modify: `routes/admin.php`. Add `GET media/{media}/source` → `admin.media.source`, with `can:update,media` (route model binding by id).
- Test: `tests/Feature/Admin/Media/MediaSourcePreviewTest.php`.

**Interfaces:**
- Produces: a JPEG preview (or PNG when the source has alpha, i.e. is PNG or WebP) of `MediaSource::for($media)`:
  - oriented, scaled to fit 2048 px on the long side and never up;
  - headers `Cache-Control: private, no-store` and `X-Content-Type-Options: nosniff`;
  - plus `X-Source-Width` and `X-Source-Height`, the oriented source size before scaling.
- It returns 404 for a non-image, SVG or animated file. HEIC originals become JPEG here, so the browser can show them.
- On a memory-guard refusal it returns 422 with `media.errors.too_large_to_edit`.

- [ ] **Step 1: Failing tests:**
  - `it serves a downscaled oriented preview of the original` (a 3000×2000 original → a 2048×1365±1 JPEG, with the source headers 3000/2000);
  - `it falls back to the current file without an original`;
  - `it is forbidden without media.edit`;
  - `it returns 404 for documents and svg`.
- [ ] **Step 2:** Run, confirm the tests fail.
- [ ] **Step 3:** Implement.
- [ ] **Step 4:** Run, confirm the tests pass.
- [ ] **Step 5:** Commit `Serve a source preview for cropping`.

### Task 7: Crop page

**Files:**
- Modify: `package.json` (`cropperjs` `1.6.3`, exact), `resources/js/app.js`.
- Create: `resources/js/media-cropper.js` (Alpine `mediaCropper`, imports `cropperjs` and its CSS).
- Modify: `routes/admin.php` (`Route::livewire('media/{media}/crop', 'pages::admin.media.crop')->name('media.crop')`, with `can:update,media`).
- Create: `resources/views/pages/admin/media/⚡crop.blade.php`.
- Modify: `resources/views/partials/admin/media/details.blade.php` (a "Crop" button, shown with `update` on editable images: raster, not animated, not SVG), `lang/*/media.php` (`crop.*`), `docs/specs/media.md` ("Crop" → Built).
- Test: `tests/Feature/Admin/Media/MediaCropPageTest.php`.

**Interfaces:**
- Consumes:
  - `CropMedia::handle(...)`;
  - `admin.media.source`;
  - `ImageEdits::outputSize()`;
  - the stored `edits`. When the original is kept, convert the stored edits back to fractions to seed Cropper: initial rotate, flips and crop box.
- Produces: component state.
  - `#[Locked] int $mediaId`.
  - `array $edits`: rotate, flip_h, flip_v, crop as fractions, size.
  - `string $ratio`, one of `free`, `1:1`, `4:3`, `3:2`, `16:9`, `social`. Social is 1200:630, and choosing it also sets the size to 1200×630.
  - `?int $width`, `?int $height`: the optional exact output size.
  - Methods `apply()` (in place, after the confirmation) and `saveCopy()`. Both call `CropMedia`, then redirect to `admin.media.index` with `folder` set to the file's folder, and `Toast::success`.
- Produces in JS (`mediaCropper`):
  - It loads the preview into Cropper with `viewMode: 1` and `autoCropArea: 1`.
  - On every `crop` event it pushes `{x, y, w, h}` to `$wire.edits.crop` as fractions of the preview's natural size **after** the rotation, using `cropper.getData()` with the rotated canvas.
  - Rotate left/right buttons call `cropper.rotate(±90)` and keep `edits.rotate` in 0/90/180/270.
  - Flips call `scaleX`/`scaleY`.
  - The aspect-ratio buttons call `setAspectRatio`.
- The page UI follows the mockup board "Crop": a full-screen layout with the image on the left and a side panel holding the ratios, the exact size, rotate/flip, the "Result: W × H px" line (from `outputSize`), and the Apply / Save as copy / Cancel buttons.
  - "Apply" opens `<x-confirm-modal>`: "The file changes everywhere it's used (:count places)".
  - "Save as copy" is shown only with `create`.

- [ ] **Step 1: Failing tests** (Livewire):
  - `it opens the crop page for an editable image`;
  - `it is forbidden without media.edit`;
  - `it is not found for svg, animated gif and documents`;
  - `it seeds the cropper from stored edits`;
  - `it applies the crop after confirmation and redirects to the library` (it asserts the version went up);
  - `it saves a copy and leaves the source unchanged`;
  - `it shows the result size` (for 4000×3000 and a crop of half by half → "2000 × 1500").
- [ ] **Step 2:** Run, confirm the tests fail.
- [ ] **Step 3:** Implement. Run `npm install cropperjs@1.6.3 --save-exact`, then `npm run build`, then `node --check resources/js/media-cropper.js`.
- [ ] **Step 4:** Run, confirm the tests pass.
- [ ] **Step 5:** Commit `Add the crop page`.

### Task 8: Replace and Restore in the details panel

**Files:**
- Modify: `resources/views/pages/admin/media/⚡index.blade.php`.
  - A `replacement` upload property, `replacementPickedSize`/`replacementPickedExtension`.
  - Methods `confirmReplace()` and `replaceConfirmed(): array{ok: bool, error?: string}`, `confirmRestore()` and `restoreConfirmed()`.
  - `#[Locked] ?int $replacingId` / `$restoringId`, cleared by `cancelPending()`.
- Modify: `resources/views/partials/admin/media/details.blade.php` (Replace and Restore buttons; Restore only with an original and `update`), `resources/views/partials/admin/media/dialogs.blade.php` (two confirm modals that name the file and the usage count).
- Modify: `resources/js/media-uploader.js`. Export the browser-side prepare step (shrink/HEIC conversion) as `prepareFile(file, config)` and reuse it for the replacement.
- Modify: `lang/*/media.php`, `docs/specs/media.md`.
- Test: `tests/Feature/Admin/Media/MediaReplaceRestoreTest.php`.

**Interfaces:**
- Consumes: `ReplaceMediaFile`, `RestoreOriginal`.
- The replacement flow:
  1. Pick a file.
  2. JS runs `prepareFile`.
  3. `$wire.upload('replacement', …)`.
  4. `confirmReplace()` opens the dialog.
  5. Confirm → `replaceConfirmed()` → toast.
  6. The details panel refreshes with the new name, size and version.
  - Errors show as a danger toast with the `MediaUploadException` text.
  - The temp upload is deleted in `finally`, as `storeUpload` does.

- [ ] **Step 1: Failing tests:**
  - `it replaces the open file after confirmation`;
  - `it refuses a replacement of another kind with a toast and keeps the file`;
  - `it restores the original after confirmation`;
  - `it hides restore without an original`;
  - `it hides replace, restore and crop without media.edit`;
  - `it forgets the pending replace or restore on cancel`.
- [ ] **Step 2:** Run, confirm the tests fail.
- [ ] **Step 3:** Implement, then run `npm run build`.
- [ ] **Step 4:** Run, confirm the tests pass.
- [ ] **Step 5:** Commit `Replace and restore media files from the details panel`.

### Task 9: Docs and full check

**Files:**
- Modify: `docs/specs/media.md`. The banner becomes "Part 2 built: crop, replace, restore, versioned URLs". Update "Calls made without the user", for example: crop works on a 2048 px preview of the source, fractions are converted on the server, and a crop without an original is baked in.
- Modify: `CLAUDE.md`. Add decision 45, "Media URLs carry `?v=`; only the current version is cached for a week", and decision 46, "One rebuild service for crop/replace/restore".

- [ ] **Step 1:** Update both files.
- [ ] **Step 2:** Run `composer test`, expect it to pass.
- [ ] **Step 3:** Run the suite on MySQL: `DB_CONNECTION=mysql DB_HOST=127.0.0.1 DB_PORT=3306 DB_DATABASE=hata_testing DB_USERNAME=root DB_PASSWORD= php artisan test --compact`. Expect it to pass.
- [ ] **Step 4:** Run `php artisan migrate --no-interaction` on the local DB (additive), then `npm run build`.
- [ ] **Step 5:** Commit `Document crop, replace and restore`.

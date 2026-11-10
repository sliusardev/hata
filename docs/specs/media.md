# Media library

> **Status: Part 1 built** (2026-11-10): library grid/list, search and filters, folders, uploads with browser resize and server optimization, details, bulk actions and ZIP download, served by route with ULID keys. Crop, optimize existing, picker, S3: not yet. Approved mockup: https://claude.ai/artifact/4ht3hKQ37R8oawQZFBCoi4 (8 screens). Sections marked "Built" describe the code; the rest is still design.

A WordPress-like library of images and documents for pages, posts and themes. On top of WordPress: folders, crop, per-language alt text, "where is it used", automatic optimization on upload, and re-optimizing files already uploaded. It must work on shared hosting: no queue worker, no SSH, often no `storage:link`, PHP `memory_limit` 128 MB, GD only (Imagick may be missing).

## Why

Pages and posts (next stages) need cover images, inline images and downloadable files. Building media first means uploads are done once. The user's concrete case: someone uploads a 20 MB phone photo, and the site should store about 150 KB without them doing anything.

**Goals:** fast to find and reuse a file; nobody has to think about file size; nothing on the public site breaks when a file is optimized, cropped or moved.
**Non-goals (this stage):** video, responsive `srcset` sizes (themes stage), image filters/brightness, sharing folders per user, a public upload API.
**Anti-goals:** a slow page with hundreds of thumbnails at full size; a server crash (memory) on a big photo; URLs in published content that change after optimization.

## Pages

| Page | Route | Name | Permission |
|---|---|---|---|
| Library (grid / list) | `/admin/media` | `admin.media.index` | `media.view` |
| Crop | `/admin/media/{media}/crop` | `admin.media.crop` | `media.edit` |
| Optimize existing files | `/admin/media/optimize` | `admin.media.optimize` | `media.edit` |
| Site settings → Media tab | `/admin/settings?tab=media` | `admin.settings.edit` | `settings.edit` |
| File (public) | `/media/{key}/{name}` | `media.show` | none |

New sidebar group "Content" with the item "Media" (shown with `media.view`). Pages and posts join this group later.

## Permissions

`config/permissions.php` → `media: view, upload, edit, delete`. Labels in `lang/*/permissions.php`. The `admin` role gets them through `*`; `editor` gets all four (RoleSeeder only adds).

- `media.upload`: upload files, "Save as copy" in crop.
- `media.edit`: alt/title, move between folders, create/rename/delete folders, crop, replace file, restore original, run optimization of existing files.
- `media.delete`: delete files (single and bulk).

## Data

**`media_folders`**: `id`, `parent_id` (nullable, self FK, cascade handled in code), `name` (≤100), timestamps. Unique (`parent_id`, `name`). Any depth; the UI indents the tree.

**`media`**:

| Column | Notes |
|---|---|
| `id`, timestamps | |
| `key` | unique ULID, the public URL key (not guessable, user's decision 2026-11-10); never changes |
| `folder_id` | nullable FK → `media_folders`, `null` = "No folder" |
| `uploaded_by` | nullable FK → `users`, `nullOnDelete` |
| `disk`, `path` | current file (after optimization/crop) |
| `original_path` | nullable; untouched upload, kept when "Keep originals" is on |
| `thumb_path` | nullable; 400 px WebP thumbnail for images (grid, list, picker) |
| `name` | display file name with extension, e.g. `hero-kyiv-autumn.webp`; part of the public URL |
| `mime`, `size` | current file |
| `width`, `height` | nullable (documents, SVG without size) |
| `original_size` | nullable; size of the upload, for "18.4 MB → 164 KB" |
| `uploaded_extension` | nullable; lowercase extension of the received file as detected (`jpg`), for "WebP (uploaded as JPG)" even when the original isn't kept; `null` on rows from before it existed |
| `optimized_with` | nullable string: fingerprint of the settings used (`webp:2560:80:exif0`), `null` = never optimized |
| `optimize_failure` | nullable string: why an image was stored without optimizing, `too_large` (memory) or `unreadable` (decode error); `null` when it was optimized or wasn't meant to be (animated, SVG, optimization off) |
| `edits` | nullable JSON: the user's crop/rotate/flip (`{crop: {x, y, w, h}` in original pixels, `rotate`, `flip_h`, `flip_v`, `size: {w, h}}`), re-applied every time the file is rebuilt from the original |
| `title` | nullable string |
| `alt` | JSON `{uk: "...", en: "..."}` |
| `search_index` | `HasSearchIndex`: name + title + all alts |

**`media_usages`**: `media_id`, `usable_type`, `usable_id`, `field` (unique together). Filled by pages/posts later through `MediaUsage::sync(Model $usable, string $field, array $mediaIds)`. In this stage the table exists and stays empty, so "Where it's used" shows "Nowhere" and the "unused" filter lists everything.

## Public URLs and serving

`/media/{key}/{name}` (e.g. `/media/01JD8X4R…/price-list.pdf`) served by `MediaController` from the media disk (default `local`, private), like the logo (decision 39): works without `storage:link`. The key is a random ULID, so the library can't be listed by counting ids; anyone who has a link can open that file. No "private file" flag in this stage.

- The URL is stable: optimizing, cropping or replacing a file keeps `key`. If the extension changes (JPG → WebP) the old `{name}` 301-redirects to the current one, so links pasted before optimization keep working.
- `Cache-Control: public, max-age=604800` plus `ETag`/`Last-Modified` (`304` on match). Not `immutable`, since the content behind a URL can change after crop.
- SVG gets the same `Content-Security-Policy … sandbox` and `nosniff` as the logo. PDF is `inline`; DOCX/XLSX are `attachment`.
- `?thumb=1` serves the thumbnail (admin views).
- Content in pages/posts should store the media id, not the URL (decided in those specs).

## Storage disks (local now, S3-compatible later)

Built storage-agnostic from the start, so moving to S3 / Cloudflare R2 / Backblaze B2 / DigitalOcean Spaces / MinIO later needs no rework:

- All file access goes through `Storage::disk($media->disk)`; no `storage_path()` or local paths in media code. Each `media` row stores its own `disk`, so old files can stay on `local` while new ones go to S3.
- New uploads go to `config('media.disk')`.
- Image processing reads the file into memory or a local temp file and writes the result back through the disk.
- Serving: for a local disk, `MediaController` streams the file (as above). For a cloud disk it **302-redirects** to the file's URL: the public bucket/CDN URL when `media.cloud_url` is set, otherwise a signed `temporaryUrl()` (1 hour). The public `/media/{key}/{name}` URL in content never changes, whichever disk the file is on.
- S3 needs `league/flysystem-aws-s3-v3`, added only when S3 is actually turned on (the S3 disk itself is already in `config/filesystems.php`).
- Moving existing files between disks: the "Optimize existing" batch mechanism gets a second mode, "Move to another disk" (copy, verify size, switch the row, delete from the old disk), plus `media:move-disk {to}` for SSH.
- **S3 settings live in the Media tab** (user's decision 2026-11-10): storage `local` / `s3`, endpoint (empty = AWS), region, bucket, access key, secret key (encrypted with `APP_KEY` like the SMTP password, never shown back), "path-style endpoint" (MinIO), public/CDN URL. "Test connection" writes, reads and deletes a small file. Saving switches only where **new** uploads go; existing files move only through "Move to another disk". `SiteSettings` writes these over `filesystems.disks.s3.*` and `media.disk`.
- This part is built as the last step of the stage, together with `league/flysystem-aws-s3-v3`.

## Upload

- Anywhere on the library page: drag-and-drop overlay (screen 2), the "Upload files" button, or the "Upload new" tab in the picker. Many files at once, sequentially (one at a time, so the hosting isn't hit in parallel).
- Allowed (user's decision 2026-11-10), checked by extension **and** detected MIME type (plus magic bytes in the first 8 KB for generically detected types; an SVG must have an `<svg` root after any XML declaration, comments or DOCTYPE within those 8 KB):
  - **Images:** JPG, PNG, WebP, GIF, SVG, AVIF, HEIC/HEIF. HEIC is converted to JPEG in the browser (Safari) or on the server (Imagick that can decode HEIC: to WebP, or JPEG when the format setting keeps the original, even with optimization off); it is never stored as HEIC, because browsers can't show it. If neither can convert it (or it is too large for the server's memory), the upload fails with "HEIC can't be converted on this server, export the photo as JPG". AVIF is stored as is when the driver can't decode it (no thumbnail, an icon instead).
  - **Documents:** PDF, DOCX, XLSX, PPTX, DOC, XLS, PPT, ODT, ODS, ODP, TXT, CSV, RTF.
  - **Other:** ZIP, MP3. Stored and served for download only, no processing.
  - The type filter in the library: all / images / documents / other.
- Route: `GET /media/{key}/{name}` (`media.show`, `MediaController`), public (no auth). The key is a ULID (stored uppercase; the route accepts either case and upper-cases it before the lookup, so SQLite and MySQL agree). An old `{name}` gets a 301 to the current URL (`?thumb=1` kept); `?thumb=1` serves the thumbnail (404 without one); a missing row or file is 404. The 301 and the cloud 302 are sent with `Cache-Control: no-cache, must-revalidate, max-age=0`, so a rename A to B to A can't loop in a browser. A disk counts as local when its configured driver is `local` (not by name); any other driver gets the 302. Local files are streamed with `Cache-Control: public, max-age=604800`, `ETag`, `Last-Modified` (`If-None-Match` gets a 304), `X-Content-Type-Options: nosniff` on every response including redirects and 304s, and SVG also gets the CSP sandbox (`default-src 'none'; style-src 'unsafe-inline'; sandbox`).
- Serving per type: images and PDF `inline`; MP3 `inline` (plays in the browser); everything else `attachment`. TXT/CSV are sent as `text/plain` attachments with `nosniff`, so a file can never run as a page.
- Limits from the Media tab (default images 30 MB, documents and other 20 MB), capped by PHP `upload_max_filesize`/`post_max_size` (the tab shows the server cap). The Livewire temporary upload rule is raised to match.
- New files go into the folder that's open (or "No folder" for "All files"), appear first in the grid with progress, and show errors on the tile itself with a reason ("PSD format is not supported", "File is too large: 34 MB, the limit is 30 MB"). The queue panel (screen 3) lists done/in progress/failed; failed ones can be removed from the queue.
  - Built: the queue is the Alpine component `mediaUploader` (`resources/js/media-uploader.js`, registered in `app.js`), configured by the page with `canUpload`, `clientResize`, `clientSide`, `accept` (extensions), `types` (extension → kind), `limits` (bytes per kind), `uploadCap` (`ServerCapabilities::uploadCapBytes()`, PHP's `upload_max_filesize`/`post_max_size` cap in bytes, `null` = none) and translated texts (all from the computed `uploaderConfig`). Dropping files anywhere on the page (only with `media.upload`) shows the overlay (it also hides on `dragend` or when no `dragover` came for 300 ms); files dropped without `media.upload` are ignored rather than opened by the browser; the "Upload files" button opens a multi-file picker. Each file is prechecked (extension, picked size against its kind's limit), then sent with `$wire.upload('upload', …)` and stored by `storeUpload()`, which returns `{ok, id, name, meta}` or `{ok: false, error}` (the `MediaUploadException` text; other failures are reported and return "The upload failed") instead of throwing, so the queue goes on with the next file. Each file goes into the folder that was open when it was queued: the queue sends it as `uploadFolderId` (null = no folder), and a folder deleted in the meantime fails that file with "The folder this file was meant for no longer exists". In-progress and failed files show as tiles before the grid; stored ones come back from the server as regular tiles (newest first). Queue rows: "Waiting…", "Preparing…" (browser resize), ":percent%", "Compressing…" (while `storeUpload` runs), "Done · compressed :before → :after" (or "Done · :size" when nothing was saved), or the error with "Remove". The panel title is "Uploaded :done of :total"; it collapses, and closes once nothing is in progress. Temporary uploads are refused (403) without `media.upload` and for any property other than `upload`.
- File names are slugged (`Str::slug`, transliterated), kept unique within the library by adding `-2`, `-3`.
- `UploadMedia` (`App\Actions\Media\UploadMedia`, `media.upload`) does it all: detect the type, check the limit against the larger of the picked and the received size (binary MB; the error shows both in MB with one decimal), optimize, write the files (`media/{Y}/{m}/{key}.{ext}`, originals in `media/originals/`, thumbnails in `media/thumbs/`), then insert the row; if the insert fails the files are deleted. Names are slugged with Ukrainian transliteration (`MediaNames::unique`, fallback `file`) and carry the extension of the stored result (`a.jpg` becomes `a.webp`). The row also stores the received file's `uploaded_extension` and the optimizer's `optimize_failure`. Rejections throw `MediaUploadException` (`media.errors.unsupported|too_large|heic_unsupported`).

### Optimization on upload

Two steps, because a 6000×4000 photo needs ~100+ MB of memory to decode on the server:

1. **In the browser** (when "Compress in the browser" and "Optimize images" are both on): JPG/PNG/WebP (and HEIC when the browser can decode it, i.e. Safari) are drawn to a canvas with EXIF orientation applied and scaled down to **3840 px** on the long side (not to `max_side`, so the kept original still allows 4K and better crops), then exported as JPEG at quality 0.92 (light, the server does the real compression); a PNG or WebP with transparency is exported as PNG instead. Only images over 3840 px are redrawn, except HEIC/HEIF, which is always converted when the browser can decode it. The file keeps its name with the new extension. GIF, SVG and other files are sent as they are. If the browser can't do it, the original file is sent. The browser also sends the size of the file the user picked; it's stored as `original_size` (the "18.4 MB" in "18.4 MB → 164 KB"), and the picked file's extension, stored as `uploaded_extension` when it is on the whitelist (so a HEIC converted to JPEG still reads "uploaded as HEIC"; anything else falls back to the received file's extension).
   - The size limits (30 MB / 20 MB) apply to the file the user picked and are checked in the browser first (instant error on the tile); the server checks what arrives against the same limit and against PHP's `upload_max_filesize`. The browser also checks the file as it will be sent against the PHP cap: a file the browser won't shrink (documents, GIF, SVG, or any file while browser resizing is off) is prechecked against `min(kind limit, cap)`, and a shrinkable image is checked against the cap after shrinking, just before upload. Both use the "File is too large" text with the limit that applied.
2. **On the server** (`OptimizeImage`, built on Laravel's own `Illuminate\Image` / `Image` facade, e.g. `Image::fromUpload($file)->orient()->scale(2560, 2560)->optimize('webp', 80)`; driver `images.default` set to `imagick` when the extension is loaded, else `gd`; the framework's GD/Imagick drivers run on `intervention/image` ^4.2): orient, scale down to max side, encode to WebP (or keep JPG/PNG when the setting says so) at the chosen quality, strip metadata when "Remove EXIF" is on. If the result isn't smaller than the input, the input is kept, unless the image was scaled down or "Remove EXIF" is on and the input carries EXIF (then the result is used). Animated images (GIF, WebP, APNG) and SVG are never re-encoded. HEIC/HEIF dimensions come from an Imagick header ping (PHP's `getimagesize` can't read them). HEIC/HEIF is always converted (`OptimizeImage::MUST_CONVERT`): to WebP, or to JPEG when the format is "original"; with optimization off it is still converted to WebP (no scaling, no EXIF step), and the "keep the input when not smaller" rule never applies to it. `UploadMedia` refuses with `heic_unsupported` whenever the result is still HEIC/HEIF (GD, an Imagick that can't decode it, or too large for memory). Before decoding, the server estimates the memory needed (width × height × 4 × overhead, from the header without decoding) against what's left under `memory_limit`. If it won't fit, the file is **stored as it is** (user's decision 2026-11-10): `optimized_with = null`, the upload tile and the details panel say "Not optimized: too large for the server to process", and it stays a candidate on the "Optimize existing" page, which skips it with the same reason while memory is still short. Any other decoding error is handled the same way.
3. A 400 px WebP thumbnail is made for every raster image.

If "Keep originals" is on (and optimization is on), the uploaded file (after the browser step) is stored as `original_path`.

## Library screen (screen 1)

- Header: title, description, "Upload files" (with `media.upload`).
- Toolbar: search (name, title, alt; 300 ms debounce), type filter (all / images / documents / other), month filter, usage filter (all / unused), "Select several", grid/list switch. Filters, folder and view are in the URL (`#[Url]`), the view also in `localStorage`. The month filter lists every month from the oldest upload to now in `admin.timezone` and filters on that month's UTC range (no database-specific date SQL). A folder id in the URL that no longer exists falls back to "All files".
- Folder tree on the left: "All files", "No folder", then folders in tree order (subfolders indented under their parent) with counts. A folder shows, and counts, only the files directly in it, not those in its subfolders. "+" creates a folder in the current one. Folder "⋮": rename, delete. **Deleting a folder deletes everything in it**: its files and all subfolders with their files (user's decision 2026-11-10). It needs `media.edit` and `media.delete`. The confirmation states how many files and subfolders will be deleted and how many of those files are used somewhere (with a link to see them), and asks to type the folder name when it holds more than 10 files. Files are moved by dragging tiles (or list rows) onto a folder or onto "No folder" (dragging a selected file moves the whole selection, shown as an "N files" drag badge; the thumbnail image itself is not draggable, so the uploader never takes a tile for a file), with "Move to…" in the bulk bar, or with the "Folder" select in the details panel. A file is in exactly one folder.
  - Built: "+" next to the "Folders" heading and the folder "⋮" menu next to the breadcrumb of the open folder are shown with `media.edit` (delete in that menu also needs `media.delete`). The name dialog shows `CreateFolder`/`RenameFolder` errors on the name field. When the folder holds more than 10 files, the confirm button stays disabled until the exact name is typed, and `deleteFolder()` checks the typed name again on the server. Deleting the open folder falls back to "All files". The "link to see them" for used files waits for the pages/posts specs; the dialog states the count. Opening a dialog the user may not use (file delete, bulk delete, new/rename/delete folder) shows the danger toast "forbidden" instead of a 403 page.
- Breadcrumb above the grid: "All files / Marketing / Banners · 12 files".
- Grid: square thumbnails (`object-fit: cover`, `loading="lazy"`), name and size under each; files without a thumbnail show the kind icon and the extension; "unused" badge on files used nowhere. 40 at first, "Show more" adds 40 (no infinite scroll), up to 400 (`GRID_MAX`); when more files exist past that, "Show more" gives way to "Showing :shown of :total — switch to the list to see all" (`media.index.grid_capped`). Grid and list share the sort (newest first by default; the list headers sort by name, size, date). Empty states: "No files yet" when the library is empty, "Nothing found" when filters match nothing.
- Details panel on click: preview, name, type ("WebP (uploaded as JPG)"), size and dimensions, uploaded date/by, green "Optimized: 18.4 MB → 164 KB (−99%)" when applicable, alt text: the primary language (the site's default language, `Media::primaryAltLocale()`) is always shown; the other `available_locales` sit in a collapsed "Translations · N of M filled in" block, one field per language with the primary text as placeholder (user's decision 2026-11-10, scales to 9+ languages); an empty translation falls back to the primary text wherever alt is rendered (`Media::altFor()`), title, folder, "Where it's used" (links), public URL with "Copy", and actions: Save, Open (new tab; clicking the preview does the same), Download, Replace, Crop, Delete. Unsaved changes in the panel are guarded like the site settings tabs when another file is clicked.
  - Built (Part 1): the type reads "WEBP (uploaded as JPG)" when `uploaded_extension` (or, on older rows, the kept original's extension) differs from the current one (JPG and JPEG count as the same); the amber "Not optimized: too large for the server to process" shows only for `optimize_failure = too_large`, "Not optimized: the server couldn't read this image" for `unreadable`, and nothing for animated images, SVG or uploads made while optimization was off; the alt switch labels the `uk` tab "UA"; "Where it's used" shows "Nowhere" or each usage as "Type #id" until pages and posts give them names; the URL is in a readonly field (selected on focus); "Copy" copies in the browser and shows a toast, and where the clipboard API is missing (plain HTTP) or refuses, it selects the field instead and shows a danger toast saying copying needs HTTPS (press Ctrl+C); Download is a link with `download`. Without `media.edit` the fields are read-only and Save is hidden; Delete needs `media.delete`. Replace and Crop are added with Part 2. The open file id is `#[Locked]` and changes only through `select()`, so the guard can't be skipped from the browser.
- Delete: **every delete is confirmed first** (`<x-confirm-modal>`): a single file, a bulk selection and a folder. The dialog names the file (or the count) and, if any file is used, lists where; deleting is still allowed (the content will show a missing image). There is no trash/undo. Deleting removes the file, thumbnail and original from the disk the file is on. Single files are deleted from the details panel or the list row "⋮" menu (which also has details, open and download); the ids of the open confirmation are `#[Locked]` and `deleteConfirmed()` deletes them (one file now, the bulk selection with Task 11). Closing or cancelling a delete dialog calls `cancelPending()`, which forgets the pending file ids and folder.
- Action errors: authorization failures show a danger toast ("You don't have permission to do this."), validation errors show on the field.
- **Replace:** upload a new file of the same kind (image → image); goes through optimization; `id` and URL stay, `name` follows the new file.

## List view (screen 4)

`flux:table` with checkbox, thumbnail + name + alt in the current locale, falling back to the primary language (for images without either, "No alt text" in amber), type, size, "Where it's used" badge (amber "Nowhere"), uploaded date, "⋮" row actions (decision 35). 25 per page, sortable by name, size, date. Selecting rows shows the bulk bar: count, "Clear selection", "Move to…" (with `media.edit`: a menu of "No folder" and the folder tree; the moved files leave the selection), "Download ZIP" (hidden when `ZipArchive` is missing), "Delete selected" (with `media.delete`, confirmation states how many are in use). "Select several" in the grid gives the same bulk bar. In the grid, Ctrl/Cmd + click adds a file to the selection or takes it out and Shift + click selects every file shown between the last clicked (or opened) file and this one; in the list, Shift + click on a checkbox does the same (`pick(id, toggle|range)`, anchor in the locked `selectionAnchor`). In the grid, drawing a box with the mouse from empty space (not from a tile, so tiles still drag onto folders) selects every tile it touches, in place of the selection or added to it with Shift or Ctrl/Cmd held (`resources/js/media-marquee.js` → `selectBox(ids, add)`); touch screens use the other ways. The "Select several" tooltip names the keys.

Built: the header checkbox selects or unselects every file on the current page (`togglePageSelection()`); in the grid, "Select several" (`toggleSelecting()`) makes a tile click pick the tile (`toggleSelected()`) with a check mark instead of opening its details. The selection keeps up to 400 ids that exist (the client's ids are re-checked on every change and in each bulk action) and is cleared when a filter or the folder changes. "Delete selected" opens the same confirmation as a single delete (`confirmBulkDelete()`, then `deleteConfirmed()`), titled "Delete the files?", stating ":count of the :total selected files are used somewhere". "Download ZIP" (`downloadZip()`) sends the browser to `GET /admin/media/zip?ids[]=…` (`admin.media.zip`, `MediaZipController`, admin group plus `can:media.view`). The route re-checks the ids (digits only, unique, existing; none left is 404, more than 100 is 422; a PHP without the zip extension gets 404), and `ZipMedia` copies each file from its own disk with a stream into a ZIP in the system temp directory (entry names made unique with `-2`, `-3`; files missing on disk are skipped). It is sent as `media.zip` and deleted after sending. An archive holds at most 100 files; over that the page shows a danger toast asking to select fewer files.

## Picker (screen 5)

A Livewire component `<livewire:media-picker>` that forms put next to a field (`wire:model` holds the media id or ids). Opens in `flux:modal`. Tabs "Library" / "Upload new". Props: `accept` (`image` / `document` / `any`), `multiple`. Same search, month filter and folders (compact) as the library; selected file preview and its alt text (editing it changes the file in the library, the modal says so). Double click selects and closes. Built now with a demo-free test page only in tests; first real use is the post cover.

## Crop (screen 6)

- Full-screen page, Cropper.js for the selection (see Dependencies). Ratios: free, 1:1, 4:3, 3:2, 16:9, "Social" (1200×630). Optional exact output size (never upscaled). Rotate left/right, flip horizontal/vertical. Shows the estimated result.
- The crop is applied on the server from the original when it's kept (better quality), otherwise from the current file; coordinates are sent relative to the image shown and mapped to the source size. The result is optimized with the current settings.
- With an original kept, the crop/rotate/flip is stored in `edits` and re-applied whenever the file is rebuilt (optimizing existing files, a settings change), so re-optimizing never undoes a crop. Without an original, the crop is baked into the file and `edits` stays empty. "Restore original" clears `edits`.
- "Apply" changes the file in place (same id and URL; the page warns how many places use it). "Save as copy" creates a new media item in the same folder (`media.upload`). "Restore original" in the details panel (when an original is kept) undoes crops and re-optimizes.

## Site settings → Media tab (screen 7)

A sixth tab `?tab=media`, saved like the others (`SiteSettingsForm::SECTIONS`, unsaved-changes guard).

| Setting | Config | Default |
|---|---|---|
| `media_optimize` | `media.optimize.enabled` | `true` |
| `media_max_side` | `media.optimize.max_side` | `2560` (choices 1920, 2560, 3840, none; "none" is stored as `0`, see site-settings.md → Media) |
| `media_quality` | `media.optimize.quality` | `80` (50–100) |
| `media_format` | `media.optimize.format` | `webp` (`webp` / `original`; `avif` shown disabled when the driver can't encode it) |
| `media_strip_exif` | `media.optimize.strip_exif` | `true` |
| `media_client_resize` | `media.optimize.client_resize` | `true` (acts only while `media_optimize` is on: the page sends `clientResize = enabled && client_resize`) |
| `media_keep_originals` | `media.optimize.keep_originals` | `true` (acts only while `media_optimize` is on: with optimization off no original is kept) |
| `media_max_image_mb`, `media_max_document_mb` | `media.limits.*` | `30`, `20` |

A new `config/media.php` holds these and `media.disk` (`MEDIA_DISK`, default `local`). The side card shows how many images don't match the current fingerprint and the estimated saving (estimate: 85% of their size for never-optimized files, 20% otherwise), with "Optimize existing" → screen 8.

## Optimize existing files (screen 8)

- Candidates: raster images (not animated GIF/WebP/APNG, not SVG) whose `optimized_with` differs from the current settings fingerprint.
- No queue worker, so the page processes them in batches while it's open: `wire:poll` calls `processBatch()`, which handles files for at most ~5 seconds per request. Progress (cursor = last processed id, counts, bytes saved, skipped) is stored in the `settings` table under `media_optimization_run`, so closing the tab and coming back resumes where it stopped. Pause stops polling; Stop clears the run.
- Each file: from the original if kept, else from the current file; skipped when the result isn't smaller ("already optimal"). URL and id stay.
- Shows: processed / total, time left (from the average so far), MB saved, average saving, skipped count, the last files with before → after.
- An artisan command `media:optimize` does the same for anyone with SSH (output in English, decision 17).

## Actions (`app/Actions/Media`)

| Action | Permission | Notes |
|---|---|---|
| `UploadMedia` | `media.upload` | validates type/size, stores original (if kept), optimizes, makes thumbnail, unique name, in a transaction with file cleanup on failure |
| `UpdateMedia` | `media.edit` | title (≤ 255), alt per available locale (≤ 500; other locales are dropped), folder |
| `ReplaceMediaFile` | `media.edit` | same kind only |
| `CropMedia` | `media.edit` (in place) / `media.upload` (copy) | |
| `RestoreOriginal` | `media.edit` | |
| `DeleteMedia` | `media.delete` | one or many, in a transaction; the file, original and thumbnail are removed from the row's own disk in `DB::afterCommit` (after the outermost commit, kept on rollback), missing files ignored |
| `CreateFolder`, `RenameFolder`, `MoveMedia` | `media.edit` | folder name is trimmed, 1–100 characters, unique among siblings (field error on `name`); a folder can't move into itself or its descendant |
| `DeleteFolder` | `media.edit` + `media.delete` | deletes the folder, all subfolders and all their files (via `DeleteMedia`, which authorizes each file), in one transaction, deepest folders first; `MediaFolder::deletionSummary()` feeds the confirmation dialog; files are removed from disk after commit |
| `OptimizeImage` | none (service used by the actions above and the batch) | |

Actions take `User $actor` and call `Gate::forUser($actor)->authorize()` (decision 25). `MediaPolicy` maps abilities to permissions.

## Dependencies

- Image processing: Laravel 13's built-in `Illuminate\Image` (`Image` facade: orient, scale, crop, rotate, flip, `optimize()`/`toWebp()`, `quality()`, `dimensions()`). Its GD and Imagick drivers need `intervention/image` ^4.2 (adding it approved by the user, 2026-11-10); the app code talks only to the Laravel API. EXIF stripping under Imagick, if the encoder keeps metadata, is done through `Image::transformUsing()` (GD drops metadata on re-encode anyway).
- `cropperjs` (npm) for the crop selection (approved by the user, 2026-11-10), loaded only on the crop page.

## Tests

`tests/Feature/Actions/Media/*` (upload with real small images generated by GD: resize, WebP, EXIF stripped, not-larger rule, animated GIF/SVG untouched, thumbnail, unique names, permissions), `tests/Feature/Admin/Media/*` (pages, access, filters, folders, bulk delete, picker, settings tab, batch optimization resume), `MediaControllerTest` (headers, 304, redirect on old name, SVG CSP, private disk). Browser-side resizing is not covered by PHP tests.

## Calls made without the user (check these)

- Folders nest to any depth; one folder per file. No trash: deletion is final after the confirmation.
- Files are served through a route from a private disk (works without `storage:link`) at the cost of PHP handling each image request.
- Old URL after JPG → WebP redirects (301) instead of keeping the `.jpg` name. Any other `{name}` for a valid key also redirects to the current one.
- Originals kept by default; "Apply" crop edits the file everywhere it's used.
- Only a 400 px thumbnail now; responsive sizes wait for the themes stage.
- 40 per page with "Show more" in the grid (up to 400), 25 in the list.
- Uploads go one at a time.
- Crops are stored as parameters and re-applied when a file is rebuilt from its original.
- The browser scales to 3840 px before upload; that file is the kept "original". Full camera resolution is not kept.
- `original_size` is the size of the file the user picked (reported by the browser); limits apply to that file.
- "Download ZIP" is capped at 100 files per archive (no size cap; the archive is a temp file sent by a plain route).
- An upload goes into the folder that was open when the file was queued, even if the user switches folders while the queue runs; if that folder is deleted meanwhile, the file fails with an error instead of landing elsewhere.
- A PNG or WebP with transparency stays PNG after browser resizing (the plan named only PNG), so WebP transparency isn't lost to JPEG.

# Media library: local images served by the web server — design

> Status: design, not built.

Date: 2026-11-12. Builds on `docs/specs/media.md` ("Storage disks", the serving route, "Move files between disks"), `docs/specs/site-settings.md` (Media tab) and CLAUDE.md decisions 41, 45, 50 and 51. Read those first; this document overrides them where it says so.

## Intent

The user's reasons, in their words and order:

1. "We must not create extra load on the site because of images." Hata is a starter kit and admin panel for many different sites. A page can hold one or two images or a large gallery; on a local disk every one of them is currently a request to PHP.
2. Most sites will run on the user's own servers, the rest on hosting with SSH. `storage:link` can be run in both places, so decision 41's reason ("shared hosting needs no `storage:link`") no longer holds.
3. It must work straight after installation, "like always in Laravel": no switch to find and turn on.
4. If the symlink is missing or broken, images must still open: served silently through the app, with a warning in the admin.

The project is still in development and only the user's local copy has uploaded files, so there is nothing deployed to stay compatible with. The user said to change whatever is needed.

## Goals

- On a new install, raster images and their thumbnails stored on this server are fetched from the web server as static files. PHP does not run for them.
- A missing or broken symlink never breaks an image: the same files are served through the existing media route until the link is repaired.
- The admin tells people with access to site settings that the link is missing, and lets them create it with one button.
- S3 behaviour does not change.
- Everything that needs the app's headers or must stay private keeps going through the app or stays off the public folder: SVG, documents, downloads, kept originals.

## Non-goals

- A general page for running artisan commands from the admin (cache, migrations, seeders, …). The user asked for it and it is the next project, with its own design. Only the "create the storage link" button belongs here.
- Private or access-controlled media. Every library file is public today (the route has no auth) and stays so.
- Image CDN features, on-the-fly resizing, responsive variants.
- Any change to how S3 disks store, serve, version or delete objects.

## Anti-goals

- An uploaded SVG reachable as a static file on the site's origin. A web server would send it as `image/svg+xml` with no sandbox, so a script inside it would run on the site. The ULID key is public (it is in `/media/{key}/{name}`), so "nobody knows the path" is not a defence.
- A kept original reachable as a static file. Originals can carry EXIF data (GPS position, camera) that optimization strips on purpose.
- A crop or replace that leaves visitors or a proxy with the old picture under the same address.
- A broken image because a deploy step was forgotten.
- Doubling disk use by keeping a private and a public copy of each image.

## Constraints

- No new dependencies. Flux free only.
- The app cannot set response headers on static files. Anything that depends on a header (sandbox, `nosniff`, disposition, real file name) must stay on the app route.
- Shared hosting may forbid `symlink()` from PHP, or the web server may not follow symlinks. Both must end in the fallback plus a warning, not an error page.
- Tests run on SQLite and MySQL; cache locks need a shared store (existing rule).
- Translations in `lang/en` and `lang/uk`; specs and CLAUDE.md decisions updated in the same change.

## What the user decided

- Direct serving is the default after install; no opt-in.
- Missing symlink: silent fallback through the app, plus a warning in the admin.
- Order of work: this first, the artisan commands page afterwards.
- No compatibility burden: only a local development copy exists.

## Design (my calls unless marked as the user's)

### Which disk holds what

Laravel's standard `public` disk (`storage/app/public`, URL `APP_URL/storage`, linked by `storage:link`) becomes the default media disk: `config/media.php` → `disk` defaults to `public` (`MEDIA_DISK`).

A media row keeps exactly one `disk`, as today. What changes is which disk an upload lands on when the site's media disk is `public`:

| File | Row's disk | Served |
|---|---|---|
| Raster image (WebP, JPEG, PNG, GIF, AVIF, …): file and thumbnail | `public` | static, by the web server |
| Its kept original | private `local` disk (see below) | never public; only read by the app (restore, crop source) |
| SVG | `local` (private) | app route, with the sandbox CSP |
| Documents, archives, video, audio, anything that is not a raster image | `local` (private) | app route (inline or attachment, real name) |

So in "this server" mode the library is a mix of `public` and `local` rows. Mixed disks are already normal (a disk move is gradual), and every flow already reads the row's own disk.

**Originals.** A row on a publishing disk keeps its original on the private counterpart, resolved by one function (for example `MediaDisks::originalsDisk($disk)`: `local` for a local disk with a public URL, otherwise the row's own disk). No new column. Every place that reads, writes, moves or deletes `original_path` goes through it: upload, `RebuildMediaFile`, `RestoreOriginal`, crop source/preview, `DeleteMedia`/`DeleteFolder`, `MoveMediaToDisk`, the prune.

**Naming the rule.** `MediaDisks::isCloud()` currently answers three different questions. Split them so the public local disk can answer "yes" to some:

- *is remote* (network, signed URLs, write options, bucket checks): S3 only. Unchanged meaning of `isCloud()`.
- *versions its paths and defers deletion*: S3 and the public local disk.
- *has direct URLs for rasters*: a disk with a public URL that is S3, or local and currently linked (below).

### The storage setting

`media_storage` holds `public` or `s3` (builder check, question 2). "This server" stores `public`; a migration rewrites a stored `local` to `public`, and the form and validation accept only the two values. There is no fully private mode any more, in the UI or through `.env`: a host where the link can never work simply stays on the automatic fallback, with files in `storage/app/public` served by the app. `local` remains only as the private disk for originals, SVG and documents. `MoveMediaToDisk::TARGETS` and `media:move-disk {to}` accept `public` and `s3`; "move everything to private `local`" is not a supported direction.

### Replace across the raster/SVG line

`ReplaceMediaFile` only requires the same kind, and SVG is kind Image, so today a PNG can be replaced by an SVG; the rebuild writes to the row's disk, which would put an SVG on the public disk (builder check, question 1). Replace therefore refuses to cross the line in either direction (raster → SVG, SVG → raster) with a translated message telling the user to upload the file as a new one. `RebuildMediaFile` is not taught to relocate a row between disks (decision 46 stays as it is). As a second guard, the write path refuses to put an SVG on a disk that publishes files.

### Paths, versions, deletion

On the public local disk, object paths carry the version exactly as on S3 (`media/{Y/m}/{key}-v{version}.{ext}`, `media/thumbs/{key}-v{version}.webp`), and replaced objects go to `media_pending_deletions` for 7 days like cloud objects. Reason: static files get whatever cache headers the web server sends, and a page rendered before a crop must keep working; a new path per version makes both safe without relying on `?v=`. Direct URLs still append `?v={version}` for consistency with S3.

The private `local` disk keeps today's unversioned paths and immediate overwrite/delete.

### URLs

`Media::directUrl()` returns the static URL (`{public disk URL}/{path}?v={version}`) for a raster on the public disk **when the link is in place**, and null otherwise, in which case `url()`/`thumbUrl()` fall back to the app route as they do now. Nothing else in the views changes: they already call `url()` and `thumbUrl()`.

The permanent address `/media/{key}/{name}` (the one "Copy" gives) stays the address to paste into content. For a raster on a linked public disk the route answers with an uncacheable 302 to the static URL, as it does for S3 with a public URL; with `?download=1`, or when the link is missing, it streams the file itself with today's headers. SVG and documents are streamed as today.

The redirect costs one PHP request per image for content that embeds the permanent address (builder check, question 5). That is accepted: "Copy" must keep giving an address that survives a crop, a replace and a disk move, and a versioned static URL does not. Everything the app renders itself calls `url()`/`thumbUrl()` and skips PHP. The pages and posts stage should store media ids and render `url()` at output time rather than store addresses; that is a note for that stage, not part of this work.

### Knowing whether direct serving works

Direct URLs are handed out only when both hold; otherwise every page silently uses the app route (the user's "silently through the app"):

1. **The link exists.** Checked on the server: the configured link for the public disk (`filesystems.links`) exists and resolves to the disk's root. At most once per request (memoized), a single `stat`.
2. **A browser has confirmed that the web server really serves it.** PHP cannot see a web server that does not follow symlinks, a rewrite rule in the way, or a wrong `APP_URL` (the public disk's URL is built from it). So direct serving starts *unconfirmed*: the admin layout, for a user who may edit site settings, fetches a small probe file the app writes to the public disk (for example `media/.probe`) at the disk's public URL and reports the result to the server, which stores it as a site setting together with the URL it was confirmed for. Until a probe has succeeded, images go through the app. The probe repeats when the state is unconfirmed or failed, when the disk URL changes, and at most once a day otherwise; a failure flips the state back.

Consequence (builder check, question 3): a fresh install serves images through the app until an administrator has opened any admin page once, which happens during setup. Nothing is ever broken in between, and there is no switch to operate. The earlier idea of a manual "Serve images through the app" switch is dropped: a failed probe does the same thing by itself.

**Static URLs that were already handed out** (cached HTML, a `/storage/media/…` address pasted somewhere) must keep working when the link disappears (builder check, question 4). Without the link those requests reach Laravel, so the app gets a route for `storage/media/{path}` that serves raster files and thumbnails from the public disk with the media route's headers (`nosniff`, cache by version), and nothing else. Today `/storage/{path}` is claimed by the private `local` disk's `'serve' => true` route for signed URLs (`config/filesystems.php`); the builder decides whether to turn that off (nothing in the app uses it) or to register the media route ahead of it.

### Admin UI

- **Site settings → Media tab, storage card.** The storage choice stays two options: "This server" and "S3-compatible". Under "This server" a status line shows one of: served directly by the web server; not checked yet (the check runs right there); link missing (button **Create the storage link**); link present but the files could not be fetched at `{url}` (with a "Check again" button and a hint to look at `APP_URL` and the web server's symlink setting). All texts translated.
- **Create the storage link** runs `storage:link` in-process (`Artisan::call`), requires the same permission as saving site settings, and reports the result with a toast. If the host forbids `symlink()` the toast says so and shows the command to run over SSH (`php artisan storage:link`).
- **Warning elsewhere.** While the link is missing or the last check failed, and the media disk is `public`, the media library page shows a callout to users who may edit site settings, linking to the Media tab. Users without that permission see nothing: images work for them either way.

### Existing files and moves

No automatic migration at deploy. Existing rows keep their disk and keep working through the app.

The disk-move mechanism (`/admin/media/move`, `media:move-disk`) gains `public` as a target and source:

- Moving to "this server" places each row where an upload would put it: rasters to `public` (original to private `local`), everything else to `local`.
- A row already where it belongs is not a candidate, so the candidate count is "rows whose disk differs from where the target mode would put them", not simply `disk != target`.
- Moving from `public`/`local` to S3 gathers the original from wherever it is.
- Versions bump on a move as today, and the insurance/pending-deletion rules apply to the public disk like to a cloud disk.

The user's local copy is brought over with this move, run once by hand.

### Web server caching

Static files need cache headers from the web server. Ship them where the project can: `public/.htaccess` gets an `Expires`/`Cache-Control` rule (one week, matching decision 45) for image types under `/storage/media/`, guarded by `IfModule`. For nginx, the README gets a short `location` snippet. Without either, browsers still cache heuristically from `Last-Modified`, and the versioned paths keep that correct.

### Tests

Feature tests for: where each kind of upload lands; originals never on the public disk; SVG never on the public disk; `url()`/`thumbUrl()` direct when linked and through the app when not (link state must be injectable in tests rather than depending on a real symlink) and confirmed, and through the app when unconfirmed or failed; the probe endpoint (permission, stored state, URL change); the `storage/media/{path}` fallback route (rasters only, no SVG, no originals); replace refusing to cross the raster/SVG line; the settings migration; the route's redirect/stream/download cases; versioned paths and deferred deletion on rebuild; the move's candidate rule and placement in both directions; the create-link action's permission, success and failure; the library callout's visibility. Existing tests that assume the `local` default are updated, not deleted.

### Documentation

`docs/specs/media.md` and `docs/specs/site-settings.md`, the README (install step `php artisan storage:link`, nginx snippet), `config/media.php` comment, `.env.example`, and CLAUDE.md: a new decision, with decision 41 marked as superseded for raster images on this server.

## Left to the builder

- Names of helpers and where the placement rule lives (one place, used by upload and move).
- The exact link check and how tests fake it.
- The probe file's name/content and the JavaScript that fetches it.
- Wording of the status line, callout and toasts (both languages).
- Whether document thumbnails (if any exist for non-raster rows) need anything; default: they follow their row to the private disk and go through the app.
- The `.htaccess` and nginx rules' exact form.

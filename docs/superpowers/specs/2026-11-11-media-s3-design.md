# Media library, Part 5: S3-compatible storage — design

> Status: built. Part 5 is built on branch `media-s3` (S3-compatible storage, direct CDN URLs, moving files between disks); where this record differs from the code after review, `docs/specs/media.md` ("Storage disks", "Move files between disks") and `docs/specs/site-settings.md` ("Media") are the current behaviour.

Date: 2026-11-11. Builds on `docs/specs/media.md` ("Storage disks (local now, S3-compatible later)", the serving route, "Optimize existing files") and `docs/specs/site-settings.md` (Media tab). Read those first; this document settles what they leave open and overrides them where it says so.

## Intent

The user's reasons, in their order:
1. Don't fill the hosting's disk with media.
2. Serve media through a CDN.
3. Move a site between servers without moving media files ("without fussing with images"): code and database move; media stay in the bucket.

It must work with any S3-compatible blob storage (AWS S3, Cloudflare R2, Backblaze B2, DigitalOcean Spaces, MinIO, …). Locally the user runs MinIO through Herd; its settings are already in `.env` (`AWS_*`, path-style endpoint, bucket `herd-bucket`).

## Goals

- An S3 disk configured from the admin (Media tab), with `.env` as the fallback (decision 36), and a "Test connection" button.
- New uploads go to the selected storage; every file of a media row (current file, thumbnail, kept original) lives on that row's disk.
- Pages reference media **directly on the CDN / public bucket URL** when one is configured (user's decision): no PHP request per image. The app's `/media/{key}/{name}` route stays and redirects (302) for old links and wherever the direct URL can't be used.
- Existing files move between disks (local → S3 and back) with the same resumable batch mechanism as "Optimize existing files" (browser / queue / CLI), plus `media:move-disk {to}`.
- SVG keeps its protection (CSP sandbox + nosniff): it is always served through the app, never by a direct bucket/CDN URL.

## Non-goals

- Bucket lifecycle, versioning, backups, replication, CDN purging.
- Image processing on the storage side (resizing stays on the server).
- Per-folder or per-file storage choice by the admin (a row's disk is set when it's written or moved).
- Private media / access control per file (media URLs stay public as today, decision 41).

## Anti-goals

- A broken image anywhere after switching storage or moving files: a page must never render a URL pointing at a disk the file isn't on.
- Losing a file during a move (delete from the old disk before the new copy is verified).
- An SVG from the bucket able to run script in the site's origin or the bucket's (served without the sandbox headers).
- Secrets leaked: the secret key is never shown back, never logged, encrypted at rest (like the SMTP password).
- A switch to S3 that makes uploads fail silently: a wrong configuration must be caught by "Test connection" and by a clear upload error, not by broken thumbnails later.

## Decisions

### Dependency
`league/flysystem-aws-s3-v3` is added (needs the user's approval with this design — Boost rule "Do not change the application's dependencies without approval"). The `s3` disk already exists in `config/filesystems.php`.

### Settings (Media tab)
As already designed in `media.md`: storage `local` / `s3`, endpoint (empty = AWS), region, bucket, access key, secret key (encrypted with `APP_KEY`, never shown back; an empty field on save keeps the stored one), "path-style endpoint" (MinIO and some others), public/CDN URL. `SiteSettings` writes them over `filesystems.disks.s3.*` and `media.disk`; stored settings win, `.env` fills the rest.
- "Test connection" writes, reads, lists and deletes a small object with the form's values (unsaved values allowed, secret falls back to the stored one); the result names what failed (credentials, bucket, endpoint, permission).
- Saving with storage `s3` is refused unless the connection test passes with the values being saved (my call: prevents a broken switch).
- Switching storage changes only where **new** files go; the tab then shows how many files are on the other disk with a link to move them.
- **Changing S3 settings while files are on S3** (user's decision): allowed, with a warning on save when bucket, endpoint, prefix or public URL change ("existing files must already be in the new location; copy them with your storage's tools first"). Switching storage back to `local` while rows are still on `s3` is allowed (new files go local), but the S3 connection settings can't be cleared while any row is on `s3` — those files must be moved first.
- **Path prefix** (user's decision): a "path prefix in the bucket" setting, empty by default, so several sites or environments can share one bucket (e.g. `prod/`, `staging/`). It's the `s3` disk's `root`, so it applies to every object path and to the direct public URL (`{public_url}/{prefix}/{path}` — the builder checks how Flysystem's prefixer and `Storage::url()` combine with a custom public URL). Changing it is covered by the warning above.

### Serving and URLs (user's decision: direct CDN)
- `Media::url()` / `thumbUrl()`:
  - local disk → the app route as today (`/media/{key}/{name}?v=`).
  - cloud disk **with** a public/CDN URL configured, and not SVG → `{public_url}/{path}?v={version}` (thumbnail: `{public_url}/{thumb_path}?v=…`).
  - cloud disk **without** a public URL, or SVG → the app route; the route then serves it (see below).
- The app route for a cloud-disk file: SVG is streamed through the app with the CSP sandbox, `nosniff` and the existing cache rules; other files get a 302 to the public URL (with `?v=`) when configured, else to a signed `temporaryUrl()` (default 1 hour, my call) with `Cache-Control: no-store` on the redirect itself. Old-name 301s keep working.
- Range/206: the app route supports byte ranges when it streams (local files and SVG on cloud), so video/PDF seeking works (named in the earlier plan).
- Objects are written with the right `Content-Type`, `Cache-Control: public, max-age=604800` (matches decision 45; the `?v=` changes when the file does), and no public ACL setting is assumed (many S3-compatible stores don't support ACLs): the public URL works only if the bucket/CDN is configured public, which the tab explains next to the field.
- Since rebuilds keep the key but may change the path (`media/{Y/m}/{key}.{ext}`), the direct URL changes with the file; `?v=` covers the case when the path stays the same.
- **The stable URL stays the app route** (my call): "Copy URL" in the details panel and anything stored in content (future editors store media ids or the app route) use `/media/{key}/{name}`; direct CDN URLs are only rendered at page-render time and never stored.
- **Versioned object paths on cloud disks** (my call): a cloud object's path includes the version, e.g. `media/{Y/m}/{key}-v{version}.{ext}` (thumbnail and original likewise), so a CDN that ignores query strings never serves a stale file, and a page rendered before a rebuild keeps working.
- **Deferred deletion of replaced cloud objects** (my call): when a rebuild or a move replaces cloud objects, the old ones are kept for 7 days (recorded in a small table of pending deletions) and removed by a cleanup that runs from the existing batch drivers / a scheduled command / opportunistically on admin requests — builder's call how, but no worker or cron may be required on shared hosting. Local files are deleted at once as today.
- **Documents and other non-images** (my call): never a direct URL. The app route redirects them to a signed `temporaryUrl()` with `ResponseContentDisposition` carrying the real file name, so downloads get `price-list.pdf`, not the ULID. Only raster images (and their thumbnails) use direct CDN URLs; SVG is streamed by the app.

### Moving files between disks
- A second mode of the existing optimization engine design (decision 48 mechanics: run state, cursor, per-file in-progress marker, lock, budgets, browser/queue/CLI drivers), or a sibling engine sharing those pieces — builder's call, but no copy-paste of the run/lock/driver code.
- Per file: copy file, thumbnail and original to the target disk; verify each copy's size; switch the row's `disk` in a transaction (row-level version guard as in `RebuildMediaFile`); delete the old copies after commit. A failure at any step leaves the row on the old disk and deletes the partial copies.
- Candidates: rows whose `disk` differs from the target. Page: "Move files to <storage>" reachable from the Media tab card and from the optimize page area (builder's call where exactly), same progress UI patterns. CLI: `media:move-disk {to}` (`local` | `s3`).
- During a move, uploads keep working (new files go to the configured disk).

### Everything else touching files
All media code already goes through `Storage::disk($media->disk)` (media.md). The builder must check every place that reads or writes media files works with a cloud disk: upload, optimize, crop/source preview, replace/restore, ZIP download, delete, thumbnails, the optimize engine (`MediaSource`), `isEditable()`'s exists check, the logo (`SiteLogo::own()` currently calls `exists()` per request — cache it or drop the check for cloud disks so public pages don't make an S3 call per request), the picker.

## Constraints

- Shared hosting: no worker, no SSH required (browser driver), PHP `memory_limit` 128 MB — streaming, never loading a large file fully when copying (use streams for copies).
- Tests: no real network in the test suite (use `Storage::fake()` for both disks and assert behaviour); one optional, skipped-by-default integration test against MinIO via env is fine (my call).
- Translations in `lang/en` + `lang/uk`; specs updated in the same commits (`media.md`, `site-settings.md`); a CLAUDE.md decision for direct CDN URLs, SVG always via the app, and disk moves.
- Flux free only. SQLite and MySQL.

## Left to the builder

- Engine sharing for moves; where the move page lives.
- Exact error texts of the connection test.
- Signed URL lifetime and Range implementation details.

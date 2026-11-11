# Media library, Part 3: optimize existing files — design

Date: 2026-11-11. Builds on `docs/specs/media.md` (screen 8 "Optimize existing files" and the Media tab of site settings, screen 7). Read that spec first; this document only adds and settles what it leaves open.

## Intent

Files already in the library should end up optimized with the current settings without anyone thinking about it. Two situations (both wanted by the user):

1. **Never optimized:** a file stored as-is because the server couldn't process it (`optimize_failure = too_large | unreadable`) or uploaded while optimization was off (`optimized_with = null`).
2. **Settings changed:** a file optimized with older settings (its `optimized_with` fingerprint, e.g. `webp:2560:80:exif0`, differs from `OptimizeImage::fingerprint()`).

It must work on shared hosting with no queue worker, and also take advantage of a real queue worker or SSH when they exist, with no setting to configure.

## Goals

- One page (`/admin/media/optimize`, `admin.media.optimize`, permission `media.edit`) that finds candidates, processes them, and shows live progress.
- The same processing reachable three ways: the open page (browser polling), a queued job, and `php artisan media:optimize`.
- Resumable: closing the tab, a request timeout or a dead worker never loses progress or corrupts a file.
- Clear reporting of what was skipped and why.
- Fold-in tech debt: store whether an image is animated, so `Media::isEditable()` and the candidate query never read the file; decode an image once per upload/rebuild instead of twice.

## Non-goals

- Moving files between disks (Part 5 adds it as a second mode of this mechanism later; keep the engine general enough that adding a mode is not a rewrite, but don't build it).
- Optimizing SVG, animated images or documents.
- A user-facing setting to choose queue vs browser.
- Upscaling or "re-inflating" quality.

## Anti-goals (failure even if it technically works)

- A URL or id changing. Optimizing goes through `RebuildMediaFile`, keeps `key`, bumps `version`, old names 301-redirect.
- A crop being undone: stored `edits` are re-applied (already how `RebuildMediaFile` + `MediaSource` work).
- A file getting worse for no reason: re-encoded from an already lossy copy for no gain, or the only full-quality copy thrown away. (A file growing because the admin chose more generous settings and an original exists is *not* worse; see Decisions.)
- A request crashing on memory, or two processes working on the same run at once.
- A run that never finishes because the same file is retried forever.

## Decisions

### Candidates

Raster images, not animated, not SVG, whose `optimized_with` is null or differs from the current fingerprint. With `media.optimize.enabled` off there are no candidates; the page says optimization is off and links to the Media settings tab.

### Processing one file

Source = `MediaSource::for($media)` (the kept original if its file exists, else the current file), edits re-applied, through `RebuildMediaFile` (no new name, keep edits). Outcomes recorded per run:

- **optimized** — file rewritten; bytes saved = old size − new size, which **may be negative**. User's decision (2026-11-11): the settings are the truth. When the source is a kept original and the result is bigger than the current file (e.g. the admin raised quality or switched the format to "original"), the result is written anyway; the page shows the change honestly (a growth is shown as "+N KB", and the run's total can be a net growth). This includes the case where `OptimizeImage` keeps its input because the result wasn't smaller: with an original as the source, the original's bytes become the current file.
- **already optimal** — only when the source is the current file (no original) and `OptimizeImage`'s "keep the input when not smaller" rule kept it. The file bytes don't change, but the row's `optimized_with` is updated to the current fingerprint so the file stops being a candidate. *(Builder's call how: through `RebuildMediaFile` or a direct column update, as long as the file isn't rewritten needlessly.)*
- **too large** (user's decision): `MemoryGuard` says it won't fit in the current process's memory. Skipped with that reason; it stays a candidate, so a later run with more memory (raised `memory_limit`, a queue worker or CLI with a higher limit) picks it up.
- **unreadable** — decode failed. Skipped with that reason; stays a candidate.
- **missing** — the file is gone from the disk. Skipped with that reason.

**Originals** (user's decision, 2026-11-11):

- A file with no kept original (e.g. uploaded while optimization was off, so its current file is the untouched upload), processed while "Keep originals" is on (and optimization on): the current bytes are saved as its original first (`RebuildMediaFile`'s `$newOriginal`), then it is optimized from them. Disk use grows by those originals; the side card's saving estimate and the run's "bytes before/after" count only the current files, not originals.
- "Keep originals" off: existing originals are left alone (never dropped by a run), and files without one are optimized from the current file.
- `keep_originals` stays out of the fingerprint: turning it on or off creates no candidates by itself.

Without a kept original, re-optimizing from the current (already lossy) file only helps when settings got stricter (smaller max side, lower quality, EXIF now stripped, format change). For "more generous" settings the result is typically not smaller and lands in "already optimal". This is accepted behaviour, not a bug; the page doesn't need to explain it per file. *(My call.)*

### The run

- A run is a snapshot: when started, it walks candidates by ascending id with a cursor (last processed id). Each candidate is visited **once per run**, so skipped files (too large, unreadable, missing) can't loop the run; they are counted and shown, and remain candidates for the next run.
- Run state lives in the `settings` table under `media_optimization_run` (as the spec says): status (running / paused / done), the fingerprint it was started with, cursor, total at start, counts per outcome, bytes before/after, started at, last progress at (heartbeat), the driver currently processing (browser / queue / cli), and the last ~10 processed files (name, before → after, outcome).
- If the settings fingerprint changes while a run exists, the run is stale: the page offers to start over. *(My call.)*
- **Pause** stops processing after the current batch; **Resume** continues from the cursor; **Stop** clears the run.
- Each batch works for a time budget (wall clock, checked between files), then saves state. The budget depends on the driver (user's request, 2026-11-11: a real queue gets bigger limits):
  - **browser:** ~5 seconds, to stay well inside a shared host's request limit;
  - **queue** (database, redis, …): larger, e.g. ~45 seconds, kept below the job's `$timeout` and the worker's `--timeout` (default 60 s) and the connection's `retry_after`, so a long batch is never killed mid-file or picked up twice;
  - **CLI:** no request limit; it still saves state every few seconds so Ctrl+C or a crash loses little.
  The worker and the CLI run with their own `memory_limit` (often higher than the web one), and `MemoryGuard` reads the current process, so files skipped as "too large" in the browser can succeed there. Exact numbers are the builder's call.

### One engine, three drivers

- **Engine:** a service that, given the run, takes the next candidates after the cursor, processes them until the time budget is used, and saves state. It doesn't know who called it. Only one batch runs at a time: a `Cache::lock` around a batch; whoever doesn't get the lock does nothing.
- **Browser driver:** the page's `wire:poll` calls the engine (`processBatch()`).
- **Queue driver:** a job that runs one batch and, if the run is still running and has candidates left, dispatches the next job.
- **CLI:** `media:optimize` creates or resumes the run and loops the engine until done, printing progress in English (decision 17). Options are the builder's call (at least resume vs restart).

### Choosing the driver automatically (user's decision: no setting)

- `queue.default` (`QUEUE_CONNECTION`) is `sync` → the browser driver; the page must stay open.
- Anything else → "Start" dispatches the job; the page only polls to show progress and says the tab can be closed.
- **Fallback:** a queue connection can be configured with no worker running (common: `database` on shared hosting). If the run's heartbeat hasn't moved for ~30 seconds after dispatch while the page is open, the page says the queue isn't processing jobs, continues in the browser, and records that it switched. If a worker appears later, the lock keeps the two from working at once.

### Media tab side card (site settings)

The spec's side card on the Media tab: number of candidates, estimated saving (85% of size for never-optimized, 20% otherwise), and an "Optimize existing" button to the page (shown with `media.edit`). If it's already built, keep it; otherwise build it.

### Fold-in: `animated` column

- New boolean column `media.animated` (default false), set wherever the file is written (`UploadMedia`, `RebuildMediaFile`) from `MediaTypes::isAnimated()`, which already runs inside `OptimizeImage::handle()`, so expose that result instead of detecting twice.
- A migration backfills existing image rows by reading each file once (chunked; a missing file counts as not animated).
- `Media::isEditable()` uses the column plus the existing "file exists" check, no longer reading the file. The candidate query filters on the column.

### Fold-in: decode once

Today an upload/rebuild decodes the source in `OptimizeImage::handle()` and the result again in `OptimizeImage::thumbnail()`. Make the optimizer produce the thumbnail from the image it already decoded (e.g. `OptimizedImage` carries the thumbnail bytes), so each file is decoded once. When the input is kept unchanged ("already optimal", animated, SVG), the thumbnail path must still work. The exact API is the builder's call.

## Constraints

- Shared hosting: no worker, no SSH, `memory_limit` 128 MB, GD only, typical 30 s request limit. A batch must finish well inside the request limit.
- Translations in `lang/en` and `lang/uk` for everything user-facing; Artisan output in English.
- Every outcome is decided by existing services (`OptimizeImage`, `MemoryGuard`, `MediaSource`, `RebuildMediaFile`); don't duplicate their rules.
- Update `docs/specs/media.md` (screen 8, Media tab card, the column, the actions table) and `site-settings.md` if the card changes it, in the same commits.
- Tests on SQLite and MySQL (CI matrix).

## Left to the builder

- Class names and file layout for the engine, job and command (follow `app/Actions/Media` and `app/Support/Media` conventions).
- The page layout, within the approved mockup for screen 8 (https://claude.ai/artifact/4ht3hKQ37R8oawQZFBCoi4) and existing admin patterns (Flux free only).
- Exact batch budget, poll interval, heartbeat timeout and lock TTL, as long as they fit the constraints above.
- How the time-left estimate is computed (from the average time per file so far).

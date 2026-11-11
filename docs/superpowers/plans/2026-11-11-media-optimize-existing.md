# Media library Part 3: optimize existing files

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Re-optimize library images that were never optimized or were optimized with older settings, from a resumable page that runs in the browser, a queue worker or the CLI; plus an `animated` column and one decode per upload/rebuild.

**Architecture:** `OptimizeImage::handle()` returns the thumbnail and the animated flag with its result (one decode). `RebuildMediaFile` is split so a caller can optimize first and store a ready `OptimizedImage`. A new engine `OptimizeExistingMedia` walks candidates by id from a cursor kept in the `settings` row `media_optimization_run`, one batch at a time under a cache lock, with a per-driver time budget; three thin drivers call it: the Livewire page (`wire:poll`), a self-chaining queued job, and `media:optimize`.

**Tech Stack:** Laravel 13 (`Illuminate\Image`, queues, `Cache::lock`), Livewire 4 SFC, Flux 2.21 free, Pest 5.

**Spec:** `docs/superpowers/specs/2026-11-11-media-optimize-existing-design.md` (approved 2026-11-11), on top of `docs/specs/media.md` (screen 8, Media tab card). Approved mockup for screen 8: https://claude.ai/artifact/4ht3hKQ37R8oawQZFBCoi4.

## Global Constraints

- Candidates: raster images, `animated = false`, not `image/svg+xml`, `optimized_with` null or `!=` `OptimizeImage::fingerprint()`. None while `media.optimize.enabled` is off.
- URL and id never change: every write goes through `RebuildMediaFile` (keeps `key`, bumps `version`, keeps `edits`).
- A result bigger than the current file is written when the source is a kept original (user's decision); "already optimal" exists only when the source is the current file.
- No original + `keep_originals` and `enabled` on → the current bytes become the original before optimizing. Existing originals are never dropped by a run.
- Bytes before/after and the side card's estimate count current files only, not originals.
- Batch budgets: browser 5 s, queue 45 s (job `$timeout` 60), CLI saves state every 5 s. Each batch processes at least one file.
- Driver: `config('queue.default') === 'sync'` → browser; else queue, with browser fallback when the heartbeat hasn't moved for 30 s.
- Every user-facing string in `lang/en` and `lang/uk` in the same commit (TranslationParityTest). Artisan output in English. Flux free only.
- `docs/specs/media.md` (and `site-settings.md` for the card) updated in the same commit as the behaviour.
- Before each commit: `vendor/bin/pint --dirty --format agent`, `composer types:check`, the affected tests. Messages end with `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`.

## Review Focus

1. A file missing from the disk, or a row deleted between the candidate query and processing: outcome `missing`, the run goes on (test in Task 4).
2. Settings changed while a run exists: the run is stale, batches refuse to process, the page offers "Start over" (tests in Tasks 4 and 6).
3. Two processors at once (browser and a late worker): the second gets no lock and processes nothing; no file is rebuilt twice (test in Task 4).
4. Queue configured, no worker running: after 30 s without heartbeat the page says so and processes in the browser (test in Task 6).
5. A cropped file with a kept original: the run re-applies `edits` and keeps them; with no original it must not bake anything new (test in Task 4).

---

### Task 1: `animated` column and decode once

**Files:**
- Create: migration `add_animated_to_media_table` (`php artisan make:migration`)
- Modify: `app/Support/Media/OptimizedImage.php`, `app/Actions/Media/OptimizeImage.php`, `app/Actions/Media/UploadMedia.php`, `app/Actions/Media/RebuildMediaFile.php`, `app/Models/Media.php`, `database/factories/MediaFactory.php`, `docs/specs/media.md`
- Test: `tests/Feature/Actions/Media/OptimizeImageTest.php`, `UploadMediaTest.php`, `RebuildMediaFileTest.php`, a new `tests/Feature/Media/AnimatedBackfillTest.php`

**Interfaces — Produces:**
- `OptimizedImage` gains `public ?string $thumbnail = null` (WebP, long side `media.thumb_side`) and `public bool $animated = false`.
- `OptimizeImage::handle()` fills both. When it decoded the image, the thumbnail comes from that same decoded image (scaled down before encoding, no second `Image::fromBytes`); when it returned the input unchanged without decoding (animated, SVG, disabled), it builds the thumbnail with the existing `thumbnail()` (one decode, as today for those). Check the `Illuminate\Image` API (search-docs) for whether operations mutate or return a new image before choosing clone-vs-reuse.
- Column `media.animated` boolean default false, cast `bool`, `@property bool $animated`, fillable; factory default false.
- `Media::isEditable()`: image, not SVG, `! $this->animated`, file exists. It no longer reads the file.
- Migration `up()`: adds the column, then backfills image rows with mime gif/webp/png in `chunkById(100)`: reads the file from the row's disk, `MediaTypes::isAnimated()`; missing or unreadable file → false.

- [ ] **Step 1: Failing tests**
  - `OptimizeImageTest`: `it('returns a thumbnail with the optimized image')` — `makeImage('jpg', 3000, 2000)` → `$result->thumbnail` not null, `getimagesizefromstring` gives 400 × 267 WebP; `it('returns a thumbnail and the animated flag for an animated gif')` — `animatedGif()` → `animated` true, thumbnail not null, contents unchanged.
  - `UploadMediaTest`: uploading `animatedGif()` stores `animated = true`; a JPEG stores false.
  - `RebuildMediaFileTest`: a rebuild from an animated GIF sets `animated` true.
  - `MediaDetailsTest` or a model test: `isEditable()` false for a row with `animated = true` whose file on disk is a plain JPEG (proves the column, not the file, decides).
  - `AnimatedBackfillTest`: create rows (animated GIF file, static PNG file, row with missing file), run the migration's `up()` (`(require database_path('migrations/…'))->up()` after dropping the column), assert true/false/false.
- [ ] **Step 2: Run them, expect failures** — `php artisan test --compact tests/Feature/Actions/Media tests/Feature/Media`
- [ ] **Step 3: Implement** the interfaces above; `UploadMedia` and `RebuildMediaFile` take `$result->thumbnail` and store `$result->animated` (documents: false) instead of calling `thumbnail()`.
- [ ] **Step 4: Full media suite passes** — `php artisan test --compact --filter=Media`
- [ ] **Step 5: Spec** — `docs/specs/media.md`: data table gets `animated`; `isEditable()` description says the column decides.
- [ ] **Step 6: Commit** "Store whether a media image is animated and decode it once"

---

### Task 2: Split `RebuildMediaFile` so a caller can store a ready result

**Files:**
- Modify: `app/Actions/Media/RebuildMediaFile.php`, `docs/specs/media.md` (actions table)
- Test: `tests/Feature/Actions/Media/RebuildMediaFileTest.php`

**Interfaces — Produces:**
- `RebuildMediaFile::store(Media $media, OptimizedImage $result, ?ImageEdits $edits, string|Closure|null $newName = null, ?string $newOriginal = null, ?string $newOriginalMime = null, bool $keepEdits = true, bool $dropOriginal = false, array $attributes = []): Media` — everything `handle()` does after optimizing (paths, name, stale files, backups/rollback, transaction, `version + 1`, `animated`). `handle()` keeps its signature and behaviour and becomes: optimize (with its existing exceptions) → `store()`.
- `RebuildMediaFile::markOptimized(Media $media, string $fingerprint): void` — sets `optimized_with` and clears `optimize_failure` without touching files or `version` (the file is byte-identical, so the cached URL stays valid).

- [ ] **Step 1: Failing tests** — `it('stores a ready optimized result')`: build an `OptimizedImage` with `OptimizeImage::handle()` from a `makeImage()`, call `store()`, assert path/size/fingerprint/version+1/thumbnail written; `it('marks a file optimized without rewriting it')`: `markOptimized()` → fingerprint set, failure null, version and file bytes unchanged.
- [ ] **Step 2: Run, expect failures** — `php artisan test --compact tests/Feature/Actions/Media/RebuildMediaFileTest.php`
- [ ] **Step 3: Implement**; existing crop/replace/restore tests are the regression net.
- [ ] **Step 4: Pass** — `php artisan test --compact --filter=Media`
- [ ] **Step 5: Commit** "Let callers store an already optimized media file"

---

### Task 3: Run state and candidates

**Files:**
- Create: `app/Support/Media/OptimizationRun.php`, `app/Support/Media/OptimizationRunStore.php`, `app/Enums/OptimizeOutcome.php`
- Modify: `app/Models/Media.php` (scope)
- Test: `tests/Feature/Media/OptimizationRunTest.php`

**Interfaces — Produces:**
- `enum OptimizeOutcome: string { Optimized = 'optimized'; AlreadyOptimal = 'already_optimal'; TooLarge = 'too_large'; Unreadable = 'unreadable'; Missing = 'missing'; }`
- `final class OptimizationRun` (mutable, `toArray()`/`fromArray()`): `string $status` (`running`|`paused`|`done`), `string $fingerprint`, `int $cursor`, `int $total`, `array<string,int> $counts` (per outcome value), `int $bytesBefore`, `int $bytesAfter`, `CarbonImmutable $startedAt`, `?CarbonImmutable $heartbeatAt`, `?string $driver` (`browser`|`queue`|`cli`), `float $secondsSpent`, `list<array{name:string, before:int, after:int, outcome:string}> $recent` (newest first, max 10). Helpers: `processed(): int`, `isStale(string $currentFingerprint): bool`, `record(Media $media, OptimizeOutcome $outcome, int $before, int $after): void` (cursor = media id, counts, bytes only for `Optimized`, recent), `secondsLeft(int $remaining): ?int` (average seconds per processed file × remaining; null before the first file).
- `OptimizationRunStore`: `get(): ?OptimizationRun`, `save(OptimizationRun $run): void`, `clear(): void`. Reads/writes the `Setting` row with key `media_optimization_run` directly (json cast); never through `SiteSettings::put()` and not in `SiteSettings::CONFIG_PATHS`, so saving progress doesn't flush the settings cache. `Cache::forget(SiteSettings::CACHE_KEY)` is not needed.
- `Media::scopeOptimizationCandidates(Builder $query, string $fingerprint): void` — the Global Constraints filter, ordered by id.

- [ ] **Step 1: Failing tests** — candidates include a JPEG with `optimized_with = null`, a WebP with an old fingerprint; exclude an SVG, an animated row, a PDF, a row with the current fingerprint. Store round-trip keeps every field; `record()` math; `isStale()`; `secondsLeft()`.
- [ ] **Step 2: Run, expect failures** — `php artisan test --compact tests/Feature/Media/OptimizationRunTest.php`
- [ ] **Step 3: Implement**
- [ ] **Step 4: Pass**
- [ ] **Step 5: Commit** "Track optimization runs and find candidates"

---

### Task 4: The engine

**Files:**
- Create: `app/Actions/Media/OptimizeExistingMedia.php`
- Test: `tests/Feature/Actions/Media/OptimizeExistingMediaTest.php`
- Modify: `app/Policies/MediaPolicy.php` (`optimize`), `docs/specs/media.md` (screen 8 section → "Built", actions table row)

**Interfaces:**
- Consumes: `OptimizeImage::handle()`/`fingerprint()` (Task 1), `RebuildMediaFile::store()`/`markOptimized()` (Task 2), `OptimizationRun`, `OptimizationRunStore`, `OptimizeOutcome`, `optimizationCandidates` (Task 3), `MediaSource::for()`, `MemoryGuard`.
- Produces:
  - `start(User $actor): OptimizationRun` — `Gate::forUser($actor)->authorize('optimize', Media::class)` (new `MediaPolicy::optimize(User $actor): bool` = `media.edit`), then `startWithoutActor()`.
  - `startWithoutActor(): OptimizationRun` — for the CLI (like decision 16): replaces any existing run with a fresh `running` one (`total` = candidate count, fingerprint = current).
  - `pause(): void`, `resume(): void` (status only), `stop(): void` (clears).
  - `processBatch(string $driver, float $budgetSeconds): ?OptimizationRun` — returns null when there's no run. Does nothing (returns the run) when the run isn't `running`, is stale, optimization is off, or `Cache::lock('media-optimization-batch', (int) ceil($budgetSeconds) + 60)` can't be acquired. Otherwise processes candidates with id > cursor until the budget is spent (checked after each file, so at least one), sets `heartbeatAt`/`driver`/`secondsSpent`, status `done` when none are left, saves, releases the lock.
  - `optimizeOne(Media $media): array{OptimizeOutcome, int, int}` (before, after), public for the tests:
    1. `MediaSource::for()` throws → `Missing`.
    2. Run `OptimizeImage::handle($source->contents, $source->mime, $media->edits)`. Edits that fail, `failure` `too_large`/`unreadable`, or a result still HEIC → `TooLarge`/`Unreadable`, nothing written.
    3. Source is the current file and `$result->contents === $source->contents` → `markOptimized()`, `AlreadyOptimal`.
    4. Else `store()` with `keepEdits: true`; `newOriginal` = source contents when the source isn't an original and `enabled && keep_originals`; never `dropOriginal`. → `Optimized` (before = old `size`, after = new `size`).

- [ ] **Step 1: Failing tests** (fake `local` disk, real images from `makeImage()`):
  - a never-optimized 3000 px JPEG without original, keep-originals on → WebP ≤ 2560 px, original now kept with the old bytes, same `key`, version +1, outcome `Optimized`.
  - an old-fingerprint file whose current bytes can't shrink (source = current file) → `AlreadyOptimal`, bytes and version unchanged, fingerprint current.
  - original kept, quality raised so the result is bigger than the current file → written anyway, `after > before`.
  - stored crop `edits` with an original → result has the cropped size, `edits` unchanged.
  - `MemoryGuard` bound with a tiny limit (`app()->instance(MemoryGuard::class, new MemoryGuard(1024))`) → `TooLarge`, nothing changed, still a candidate.
  - file deleted from disk → `Missing`; the batch continues with the next file.
  - `processBatch('browser', 0)` processes exactly one file and advances the cursor; a second call the next.
  - skipped files are not revisited in the same run (cursor), run ends `done` with counts.
  - stale run (change `media.optimize.quality` after `start()`) → `processBatch` processes nothing.
  - held lock (`Cache::lock('media-optimization-batch', 60)->get()` first) → processes nothing.
  - optimization off → processes nothing.
  - `start()` without `media.edit` → `AuthorizationException`.
- [ ] **Step 2: Run, expect failures** — `php artisan test --compact tests/Feature/Actions/Media/OptimizeExistingMediaTest.php`
- [ ] **Step 3: Implement**
- [ ] **Step 4: Pass** (and the whole media suite)
- [ ] **Step 5: Spec + commit** "Optimize existing media files in resumable batches"

---

### Task 5: Queue job and `media:optimize`

**Files:**
- Create: `app/Jobs/OptimizeExistingMediaBatch.php` (`php artisan make:job`), `app/Console/Commands/OptimizeMedia.php` (`php artisan make:command`)
- Test: `tests/Feature/Media/OptimizeExistingMediaJobTest.php`, `tests/Feature/Console/OptimizeMediaCommandTest.php`
- Modify: `docs/specs/media.md`

**Interfaces — Produces:**
- Job: `ShouldQueue`, `$timeout = 60`, `$tries = 1`, `const float BUDGET = 45.0`. `handle(OptimizeExistingMedia $engine)` calls `processBatch('queue', self::BUDGET)`; if the returned run is `running`, not stale and still has candidates, dispatches itself again. No `ShouldBeUnique`: the batch lock is the guard.
- Command `media:optimize {--restart : Discard the current run and start over}`: resumes a running/paused, non-stale run (sets it running) or calls `startWithoutActor()`, then loops `processBatch('cli', 5.0)` printing `Processed N/T · saved X MB · skipped K` per batch, finally a summary table per outcome. Exits 0; with optimization off prints "Optimization is off in the media settings." and exits 1.

- [ ] **Step 1: Failing tests** — job: with `Queue::fake()`, handling one batch with candidates left pushes the next job; with none left pushes nothing and the run is `done`. Command: three candidates → output contains `3/3`, all optimized, exit 0; `--restart` resets counts; optimization off → exit 1.
- [ ] **Step 2: Run, expect failures**
- [ ] **Step 3: Implement**
- [ ] **Step 4: Pass**
- [ ] **Step 5: Spec + commit** "Run media optimization from a queue or the command line"

---

### Task 6: The "Optimize existing files" page

**Files:**
- Create: `resources/views/pages/admin/media/⚡optimize.blade.php`
- Modify: `routes/admin.php` (`Route::livewire('media/optimize', 'pages::admin.media.optimize')->middleware('can:media.edit')->name('media.optimize')`, before `media/{media}/…`), `lang/{en,uk}/media.php` (`media.optimize.*`), `docs/specs/media.md`
- Test: `tests/Feature/Admin/Media/MediaOptimizePageTest.php`

**Interfaces:**
- Consumes: the engine (Task 4), the job (Task 5), `OptimizationRunStore`.
- Page state: `#[Computed] run(): ?OptimizationRun`, `#[Computed] candidateCount(): int`, `#[Computed] queueAvailable(): bool` (`config('queue.default') !== 'sync'`), `bool $browserFallback` (session-free page property, also set when the run's driver says so).
- Actions: `start()` (engine `start()`; queue available → dispatch the job, else nothing — polling processes), `pause()`, `resume()` (queue → dispatch again), `stop()` (via `<x-confirm-modal>`), `startOver()` (for a stale run: `start()` again), `tick()` — the `wire:poll.2s` target while the run is `running`:
  - browser mode (no queue, or `$browserFallback`) → `processBatch('browser', 5.0)`;
  - queue mode → only refreshes; if `heartbeatAt` (or `startedAt` when null) is older than 30 s, set `$browserFallback = true` and show the callout "The queue isn't processing jobs, continuing in this tab".
- View (mockup screen 8, Flux free): header with title and back link to the library; when optimization is off a callout linking to `admin.settings.edit?tab=media`; idle: candidate count + "Start"; running/paused: progress bar (processed / total), time left, MB saved (net, may be negative, "+N MB" for growth), average saving, skipped count by reason, the recent list (name, before → after, outcome badge), Pause/Resume/Stop; a line saying the tab can be closed in queue mode and must stay open in browser mode; stale: callout + "Start over"; done: summary + "Start a new run" when candidates remain (the skipped ones).

- [ ] **Step 1: Failing tests** — guests/without `media.edit` get 403; with optimization off the settings link shows; `start()` with `queue.default = sync` creates a run and `tick()` processes files; with `queue.default = database` and `Queue::fake()`, `start()` pushes the job and `tick()` processes nothing; then `travel(31)->seconds()` and `tick()` → fallback callout visible and files processed; stale run shows "Start over"; `stop()` clears the run.
- [ ] **Step 2: Run, expect failures** — `php artisan test --compact tests/Feature/Admin/Media/MediaOptimizePageTest.php`
- [ ] **Step 3: Implement** (load `livewire-development` and `fluxui-development` skills)
- [ ] **Step 4: Pass**; check the page in the browser in light and dark mode
- [ ] **Step 5: Spec + commit** "Page for optimizing existing media files"

---

### Task 7: Media tab side card

**Files:**
- Modify: `resources/views/pages/admin/settings/⚡edit.blade.php` (Media tab), `lang/{en,uk}/site_settings.php`, `docs/specs/site-settings.md`, `docs/specs/media.md`
- Test: `tests/Feature/Admin/SiteSettings…` (the existing media-tab test file)

**Interfaces — Consumes:** `Media::optimizationCandidates()` (Task 3), route `admin.media.optimize`.

- Card on the Media tab (shown while optimization is on and the actor has `media.edit`): number of candidates and estimated saving = Σ(size × 0.85) for `optimized_with` null + Σ(size × 0.20) for the rest, formatted like the library's sizes; a running run shows its progress instead; button "Optimize existing" → `admin.media.optimize`. Zero candidates: "All images match the current settings". Counted with the stored settings, not unsaved form values.

- [ ] **Step 1: Failing tests** — two candidates (1 MB never optimized, 1 MB old fingerprint) → card shows `2` and ≈ `1.1 MB`; none → the "all match" text; without `media.edit` no card.
- [ ] **Step 2: Run, expect failures**
- [ ] **Step 3: Implement**
- [ ] **Step 4: Pass**, then the whole suite on SQLite and MySQL (`composer test`, and the MySQL run as in CI)
- [ ] **Step 5: Specs + commit** "Show optimization candidates on the media settings tab"

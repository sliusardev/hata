<?php

namespace Hata\Actions\Media;

use Hata\Contracts\AdminUser;
use Hata\Enums\MediaKind;
use Hata\Enums\MoveOutcome;
use Hata\Exceptions\MediaChangedException;
use Hata\Exceptions\MediaWriteException;
use Hata\Models\Media;
use Hata\Models\MediaPendingDeletion;
use Hata\Support\Media\DiskMoveRun;
use Hata\Support\Media\DiskMoveRunStore;
use Hata\Support\Media\MediaBatchRun;
use Hata\Support\Media\MediaDisks;
use Hata\Support\Media\MediaPaths;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * The engine of "Move files to another disk": walks the rows that are not where the target storage would put them
 * (candidates()) by ascending id and moves each one there, in batches through the shared MediaBatchEngine mechanics.
 *
 * Each row goes where an upload would put it (destinationFor()): to "this server" (`public`) raster images go to the
 * public disk with their original on the private disk, everything else to the private disk; to S3 everything goes
 * to S3, the original gathered from wherever it is. File and thumbnail are on the row's disk, the original on its
 * originals disk (MediaDisks::originalsDisk()), before and after the move.
 *
 * One file: copy every object with streams, check each copy's size, then switch `disk`, the paths and the version in a
 * transaction that first checks the row still has the version loaded. The version goes up so `?v=` and the versioned
 * paths change (a row moved back to a disk never reuses a path still waiting for its deferred deletion). After commit
 * each old object follows the rule of the disk it was on: on a disk that versions its paths (cloud, public) it waits
 * 7 days (pages and CDN caches may still point at it); on the private disk it is deleted at once. A failure leaves the
 * row as it was and deletes the copies. The move holds the row's rebuild lock, so a crop, replace or optimization of
 * the same file can't run at the same time.
 *
 * Before copying, each new path is recorded as a pending deletion on its disk, due in INSURANCE_HOURS; the commit (or a
 * clean failure) drops those rows, so the prune only sweeps what a process killed mid-copy left behind.
 *
 * @extends MediaBatchEngine<DiskMoveRun>
 */
class MoveMediaToDisk extends MediaBatchEngine
{
    public const string LOCK = 'media-move-batch';

    /**
     * The storages media can be moved to (the storage choices of the media settings). "This server" is `public`:
     * it places raster images on the public disk and everything else on the private one.
     *
     * @var list<string>
     */
    public const array TARGETS = [MediaDisks::PUBLIC, 's3'];

    /**
     * Seconds one file's copy may take in the CLI (no time limit there): the row lock lives this long and the batch lock
     * this much past its budget. The browser and the queue are bounded much tighter (see lockSeconds()); a file that
     * outlives them is abandoned and its copies are swept, so very large files go through `media:move-disk`.
     */
    public const int FILE_SECONDS = 1800;

    /** Seconds kept between a queue batch's lock (and job timeout) and the connection's retry_after. */
    public const int RETRY_MARGIN = 10;

    /** The least a queue batch's lock and job timeout can be clamped to, however small retry_after is. */
    public const int MIN_QUEUE_SECONDS = 15;

    /** Hours after which the copies of a move that never finished (its process died) are swept by the prune. */
    public const int INSURANCE_HOURS = 24;

    public function __construct(private DiskMoveRunStore $runs) {}

    /**
     * Start a fresh move (replacing any existing one) to the given disk as the given user.
     *
     * @throws InvalidArgumentException when the disk isn't a media disk or isn't configured
     */
    public function start(AdminUser $actor, string $target): DiskMoveRun
    {
        Gate::forUser($actor)->authorize('moveToDisk', Media::class);

        return $this->startWithoutActor($target);
    }

    /**
     * Start a fresh move without an actor (the CLI), replacing any existing one. Nothing is saved when it's refused.
     *
     * @throws InvalidArgumentException when the disk isn't a media disk or isn't configured
     */
    public function startWithoutActor(string $target): DiskMoveRun
    {
        if (! in_array($target, self::TARGETS, true)) {
            throw new InvalidArgumentException("Unknown disk [{$target}]; use one of: ".implode(', ', self::TARGETS).'.');
        }

        if (MediaDisks::isCloud($target) && blank(config("filesystems.disks.{$target}.bucket"))) {
            throw new InvalidArgumentException("The disk [{$target}] is not configured: set its connection in the media settings first.");
        }

        $run = new DiskMoveRun(
            id: (string) Str::ulid(),
            status: 'running',
            target: $target,
            cursor: 0,
            total: self::candidates($target)->count(),
            counts: [],
            startedAt: now()->toImmutable(),
            heartbeatAt: now()->toImmutable(),
        );

        $this->runs->save($run);

        return $run;
    }

    /**
     * The disk a move to $target puts the row on: where an upload of the same file would go (MediaDisks::diskFor()).
     */
    public static function destinationFor(Media $media, string $target): string
    {
        return MediaDisks::diskFor($target, $media->kind, $media->mime);
    }

    /**
     * The rows a move to $target has to move: those whose disk differs from destinationFor(). Where a row's original is
     * never makes it a candidate. For a publishing target the raster test is MediaDisks::isRasterImage() in SQL.
     *
     * @return Builder<Media>
     */
    public static function candidates(string $target): Builder
    {
        $query = Media::query();

        if (! MediaDisks::publishes($target)) {
            return $query->where('disk', '!=', $target);
        }

        return $query->where(fn (Builder $query) => $query
            ->where(fn (Builder $raster) => $raster
                ->where('disk', '!=', $target)
                ->where('kind', MediaKind::Image)
                ->where('mime', '!=', MediaDisks::SVG_MIME))
            ->orWhere(fn (Builder $other) => $other
                ->where('disk', '!=', MediaDisks::PRIVATE)
                ->where(fn (Builder $notRaster) => $notRaster->where('kind', '!=', MediaKind::Image)->orWhere('mime', MediaDisks::SVG_MIME))));
    }

    /**
     * Move one row's objects to where the target storage puts it. Never throws: an unexpected error is reported and
     * counted as failed.
     */
    public function moveOne(Media $media, string $target): MoveOutcome
    {
        $version = (int) $media->version;
        $lock = Cache::lock(RebuildMediaFile::lockName($media), $this->rowLockSeconds());

        // A crop, replace or optimization of this row is writing right now.
        if (! $lock->get()) {
            return MoveOutcome::Changed;
        }

        try {
            return $this->moveLocked($media, $target, $version);
        } catch (MediaChangedException $e) {
            return $e->gone ? MoveOutcome::Missing : MoveOutcome::Changed;
        } catch (Throwable $e) {
            report($e);

            return MoveOutcome::Failed;
        } finally {
            $lock->release();
        }
    }

    protected function runs(): DiskMoveRunStore
    {
        return $this->runs;
    }

    protected function lockName(): string
    {
        return self::LOCK;
    }

    /**
     * The queue's retry_after for a connection (the default one when null); null when it has none (sync).
     */
    public static function retryAfter(?string $connection = null): ?int
    {
        $retryAfter = config('queue.connections.'.($connection ?? config('queue.default')).'.retry_after');

        return is_numeric($retryAfter) ? (int) $retryAfter : null;
    }

    /**
     * Seconds a queue batch (its lock and its job's timeout) may take at most: below the connection's retry_after,
     * else a second worker would be handed the job while it still runs.
     */
    public function clampToRetryWindow(int $seconds, ?string $connection = null): int
    {
        $retryAfter = self::retryAfter($connection);

        if ($retryAfter === null) {
            return $seconds;
        }

        return min($seconds, max($retryAfter - self::RETRY_MARGIN, self::MIN_QUEUE_SECONDS));
    }

    /**
     * Only the CLI may keep one file's locks for FILE_SECONDS; the browser keeps a short margin like optimization
     * and the queue stays inside the retry window.
     */
    protected function fileSeconds(string $driver): int
    {
        return match ($driver) {
            'cli' => self::FILE_SECONDS,
            'queue' => self::FILE_SECONDS,
            default => parent::fileSeconds($driver),
        };
    }

    public function lockSeconds(float $budgetSeconds, string $driver = 'browser', ?string $connection = null): int
    {
        $seconds = parent::lockSeconds($budgetSeconds, $driver);

        return $driver === 'queue' ? $this->clampToRetryWindow($seconds, $connection) : $seconds;
    }

    private function rowLockSeconds(): int
    {
        $seconds = $this->fileSeconds($this->driver);

        return $this->driver === 'queue' ? $this->clampToRetryWindow($seconds) : $seconds;
    }

    /**
     * Whether a PHP function can be called here (a host may list it in disable_functions, which makes it undefined).
     */
    protected function functionExists(string $function): bool
    {
        return function_exists($function);
    }

    protected function setTimeLimit(int $seconds): void
    {
        @set_time_limit($seconds);
    }

    protected function ignoreUserAbort(bool $ignore): int
    {
        return ignore_user_abort($ignore);
    }

    protected function isProcessable(MediaBatchRun $run): bool
    {
        return true;
    }

    protected function next(MediaBatchRun $run): ?Media
    {
        return self::candidates($run->target)->where('id', '>', $run->cursor)->orderBy('id')->first();
    }

    protected function processFile(MediaBatchRun $run, Media $media): void
    {
        $run->record($media, $this->moveOne($media, $run->target));
    }

    protected function recordAbandoned(MediaBatchRun $run, Media $media): void
    {
        $run->record($media, MoveOutcome::Failed);
    }

    /**
     * moveOne() under the row's lock.
     *
     * @throws MediaChangedException when the row changed or went away after it was loaded
     */
    private function moveLocked(Media $media, string $target, int $version): MoveOutcome
    {
        $stored = Media::query()->whereKey($media->getKey())->first(['id', 'version', 'disk']);

        if ($stored === null) {
            return MoveOutcome::Missing;
        }

        if ((int) $stored->version !== $version || $stored->disk !== $media->disk) {
            return MoveOutcome::Changed;
        }

        $from = $media->disk;
        $to = self::destinationFor($media, $target);

        // Nothing to move: counted as skipped, not as moved (the batch only picks rows that are elsewhere).
        if ($from === $to) {
            return MoveOutcome::Changed;
        }

        if (! Storage::disk($from)->exists($media->path)) {
            return MoveOutcome::Missing;
        }

        $next = $version + 1;
        $now = now();
        /** @var array<string, array{0: string, 1: ?string, 2: string, 3: ?string, 4: ?string}> $copies Column => source disk, old path, destination disk, new path, MIME. */
        $copies = [
            'path' => [$from, $media->path, $to, MediaPaths::file($to, $media->key, $next, pathinfo($media->path, PATHINFO_EXTENSION), $now), $media->mime],
            'thumb_path' => [$from, $media->thumb_path, $to, MediaPaths::thumb($to, $media->key, $next), 'image/webp'],
            'original_path' => [$media->originalsDisk(), $media->original_path, MediaDisks::originalsDisk($to), $this->originalPath($media, $to, $next), null],
        ];

        /** @var list<array{0: string, 1: string}> $written Disk and path of each copy written. */
        $written = [];
        /** @var list<array{0: string, 1: string}> $old Disk and path of each object the moved row no longer uses. */
        $old = [];
        $attributes = ['disk' => $to, 'version' => $next];
        $attributesBefore = $media->getAttributes();
        $originalBefore = $media->getRawOriginal();
        /** @var list<array{0: string, 1: string}> $insured */
        $insured = [];

        foreach ($copies as [, $oldPath, $on, $newPath]) {
            if ($oldPath !== null && $oldPath !== '' && $newPath !== null) {
                $insured[] = [$on, $newPath];
            }
        }

        // A process killed mid-copy (time limit, worker timeout, deploy) never reaches the cleanup below.
        foreach (self::byDisk($insured) as $on => $paths) {
            MediaPendingDeletion::schedule($on, $paths, now()->addHours(self::INSURANCE_HOURS));
        }

        // A large file can outlast PHP's time limit (lifted for the rest of this request or batch), and a closed tab
        // must not abort the copy halfway; the queue worker's own timeout is the job's (MoveMediaBatch). A host can
        // disable either function, which then simply isn't called.
        if ($this->functionExists('set_time_limit')) {
            $this->setTimeLimit(0);
        }

        $ignoredAbort = $this->functionExists('ignore_user_abort') ? $this->ignoreUserAbort(true) : null;

        try {
            foreach ($copies as $column => [$sourceDisk, $oldPath, $destinationDisk, $newPath, $mime]) {
                $source = Storage::disk($sourceDisk);

                // A missing thumbnail or original is dropped (the file itself was checked above).
                if ($oldPath === null || $oldPath === '' || $newPath === null || ! $source->exists($oldPath)) {
                    $attributes[$column] = null;

                    continue;
                }

                $written[] = [$destinationDisk, $newPath];
                $this->copy($source, $oldPath, $destinationDisk, $newPath, $mime ?? ($source->mimeType($oldPath) ?: 'application/octet-stream'));
                $attributes[$column] = $newPath;
                $old[] = [$sourceDisk, $oldPath];
            }

            DB::transaction(function () use ($media, $version, $attributes, $old, $insured): void {
                $stored = Media::query()->whereKey($media->getKey())->lockForUpdate()->value('version');

                if ($stored === null) {
                    throw MediaChangedException::gone();
                }

                if ((int) $stored !== $version) {
                    throw MediaChangedException::changed();
                }

                $media->forceFill($attributes)->save();
                $this->dropInsurance($insured);

                $deleteNow = [];

                foreach (self::byDisk($old) as $on => $paths) {
                    // Pages and CDN or browser caches may still point at objects on a versioned disk: they go after the delay.
                    if (MediaDisks::versionsPaths($on)) {
                        MediaPendingDeletion::schedule($on, $paths);
                    } else {
                        $deleteNow[$on] = $paths;
                    }
                }

                if ($deleteNow === []) {
                    return;
                }

                DB::afterCommit(function () use ($deleteNow): void {
                    foreach ($deleteNow as $on => $paths) {
                        try {
                            Storage::disk($on)->delete($paths);
                        } catch (Throwable $e) {
                            report($e);
                        }
                    }
                });
            });
        } catch (Throwable $e) {
            $removed = $this->deleteCopies($written);
            // Copies that could not be removed keep their insurance, so the prune retries them.
            $this->dropInsurance(array_values(array_filter(
                $insured,
                fn (array $copy): bool => ! in_array($copy, $written, true) || in_array($copy, $removed, true),
            )));
            $media->setRawAttributes($originalBefore, sync: true);
            $media->setRawAttributes($attributesBefore);

            throw $e;
        } finally {
            if ($ignoredAbort !== null) {
                $this->ignoreUserAbort($ignoredAbort !== 0);
            }
        }

        return MoveOutcome::Moved;
    }

    /**
     * Paths grouped by the disk they are on.
     *
     * @param  list<array{0: string, 1: string}>  $objects  Disk and path of each object.
     * @return array<string, list<string>>
     */
    private static function byDisk(array $objects): array
    {
        $grouped = [];

        foreach ($objects as [$disk, $path]) {
            $grouped[$disk][] = $path;
        }

        return $grouped;
    }

    /**
     * The original's path on the destination, named with the row's new disk (versioned when that disk versions its
     * paths) and keeping its extension; null when the row has none.
     */
    private function originalPath(Media $media, string $disk, int $version): ?string
    {
        if ($media->original_path === null || $media->original_path === '') {
            return null;
        }

        return MediaPaths::original($disk, $media->key, $version, pathinfo($media->original_path, PATHINFO_EXTENSION) ?: 'bin');
    }

    /**
     * Stream one object to its destination disk (never loading it whole) and check the copy has the source's size.
     *
     * @throws MediaWriteException when an SVG is meant for a disk that publishes its files
     * @throws RuntimeException when the source can't be read, the write fails or the sizes differ
     */
    private function copy(Filesystem $source, string $from, string $disk, string $to, string $mime): void
    {
        // The same guard as MediaPaths::write(): destinationFor() never sends an SVG there.
        if ($mime === MediaDisks::SVG_MIME && MediaDisks::publishes($disk)) {
            throw MediaWriteException::svgOnPublishingDisk($disk, $to);
        }

        $destination = Storage::disk($disk);
        $stream = $source->readStream($from);

        if (! is_resource($stream)) {
            throw new RuntimeException("Could not read [{$from}] for the move.");
        }

        try {
            if ($destination->writeStream($to, $stream, MediaPaths::writeOptions($disk, $mime)) === false) {
                throw new RuntimeException("Could not write [{$to}] on the [{$disk}] disk.");
            }
        } finally {
            // The write may have closed the stream already (a closed resource's type is "Unknown").
            if (get_resource_type($stream) === 'stream') {
                fclose($stream);
            }
        }

        if ($destination->size($to) !== $source->size($from)) {
            throw new RuntimeException("The copy of [{$from}] on the [{$disk}] disk has another size.");
        }
    }

    /**
     * Remove the copies a failed move wrote. A failed cleanup is reported; the original error matters more.
     *
     * @param  list<array{0: string, 1: string}>  $copies  Disk and path of each copy.
     * @return list<array{0: string, 1: string}> The copies removed.
     */
    private function deleteCopies(array $copies): array
    {
        $removed = [];

        foreach ($copies as $copy) {
            try {
                if (Storage::disk($copy[0])->delete($copy[1])) {
                    $removed[] = $copy;
                }
            } catch (Throwable $e) {
                report($e);
            }
        }

        return $removed;
    }

    /**
     * Forget the pending deletions that insured the copies of this move.
     *
     * @param  list<array{0: string, 1: string}>  $copies  Disk and path of each copy.
     */
    private function dropInsurance(array $copies): void
    {
        foreach (self::byDisk($copies) as $disk => $paths) {
            MediaPendingDeletion::query()->where('disk', $disk)->whereIn('path', $paths)->delete();
        }
    }
}

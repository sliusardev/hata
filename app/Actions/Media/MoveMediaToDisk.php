<?php

namespace App\Actions\Media;

use App\Enums\MoveOutcome;
use App\Exceptions\MediaChangedException;
use App\Models\Media;
use App\Models\MediaPendingDeletion;
use App\Models\User;
use App\Support\Media\DiskMoveRun;
use App\Support\Media\DiskMoveRunStore;
use App\Support\Media\MediaBatchRun;
use App\Support\Media\MediaDisks;
use App\Support\Media\MediaPaths;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * The engine of "Move files to another disk": walks the rows that are not on the target disk by ascending id and
 * moves each one (file, thumbnail, original) there, in batches through the shared MediaBatchEngine mechanics.
 *
 * One file: copy every object with streams, check each copy's size, then switch `disk`, the paths and the version in a
 * transaction that first checks the row still has the version loaded. The version goes up so `?v=` and the versioned
 * cloud paths change (a row moved back to a cloud disk never reuses a path still waiting for its deferred deletion).
 * After commit the old objects on a cloud disk wait 7 days (pages and CDN caches may still point at them); old local
 * files are deleted at once. A failure leaves the row as it was and deletes the copies. The move holds the row's
 * rebuild lock, so a crop, replace or optimization of the same file can't run at the same time.
 *
 * Before copying, each target path is recorded as a pending deletion due in INSURANCE_HOURS; the commit (or a clean
 * failure) drops those rows, so the prune only sweeps what a process killed mid-copy left behind.
 *
 * @extends MediaBatchEngine<DiskMoveRun>
 */
class MoveMediaToDisk extends MediaBatchEngine
{
    public const string LOCK = 'media-move-batch';

    /**
     * The disks media can be moved to (the storage choices of the media settings).
     *
     * @var list<string>
     */
    public const array TARGETS = ['local', 's3'];

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
    public function start(User $actor, string $target): DiskMoveRun
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
            total: Media::query()->where('disk', '!=', $target)->count(),
            counts: [],
            startedAt: now()->toImmutable(),
            heartbeatAt: now()->toImmutable(),
        );

        $this->runs->save($run);

        return $run;
    }

    /**
     * Move one row's objects to the target disk. Never throws: an unexpected error is reported and counted as failed.
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
        return Media::query()->where('disk', '!=', $run->target)->where('id', '>', $run->cursor)->orderBy('id')->first();
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

        // Nothing to move: counted as skipped, not as moved (the batch only picks rows on another disk).
        if ($media->disk === $target) {
            return MoveOutcome::Changed;
        }

        $from = $media->disk;
        $source = Storage::disk($from);

        if (! $source->exists($media->path)) {
            return MoveOutcome::Missing;
        }

        $next = $version + 1;
        $now = now();
        $copies = [
            'path' => [$media->path, MediaPaths::file($target, $media->key, $next, pathinfo($media->path, PATHINFO_EXTENSION), $now), $media->mime],
            'thumb_path' => [$media->thumb_path, MediaPaths::thumb($target, $media->key, $next), 'image/webp'],
            'original_path' => [$media->original_path, $this->originalPath($media, $target, $next), null],
        ];

        $destination = Storage::disk($target);
        $written = [];
        $attributes = ['disk' => $target, 'version' => $next];
        $old = [];
        $attributesBefore = $media->getAttributes();
        $originalBefore = $media->getRawOriginal();
        $insured = array_values(array_filter(array_map(
            fn (array $copy): ?string => $copy[0] === null || $copy[0] === '' ? null : $copy[1],
            $copies,
        )));

        // A process killed mid-copy (time limit, worker timeout, deploy) never reaches the cleanup below.
        MediaPendingDeletion::schedule($target, $insured, now()->addHours(self::INSURANCE_HOURS));

        // A large file can outlast PHP's time limit (lifted for the rest of this request or batch), and a closed tab
        // must not abort the copy halfway; the queue worker's own timeout is the job's (MoveMediaBatch). A host can
        // disable either function, which then simply isn't called.
        if ($this->functionExists('set_time_limit')) {
            $this->setTimeLimit(0);
        }

        $ignoredAbort = $this->functionExists('ignore_user_abort') ? $this->ignoreUserAbort(true) : null;

        try {
            foreach ($copies as $column => [$oldPath, $newPath, $mime]) {
                // A missing thumbnail or original is dropped (the file itself was checked above).
                if ($oldPath === null || $oldPath === '' || $newPath === null || ! $source->exists($oldPath)) {
                    $attributes[$column] = null;

                    continue;
                }

                $written[] = $newPath;
                $this->copy($source, $oldPath, $destination, $target, $newPath, $mime ?? ($source->mimeType($oldPath) ?: 'application/octet-stream'));
                $attributes[$column] = $newPath;
                $old[] = $oldPath;
            }

            DB::transaction(function () use ($media, $version, $attributes, $from, $old, $target, $insured): void {
                $stored = Media::query()->whereKey($media->getKey())->lockForUpdate()->value('version');

                if ($stored === null) {
                    throw MediaChangedException::gone();
                }

                if ((int) $stored !== $version) {
                    throw MediaChangedException::changed();
                }

                $media->forceFill($attributes)->save();
                $this->dropInsurance($target, $insured);

                if (MediaDisks::isCloud($from)) {
                    // Pages and CDN caches may still point at the old objects: they go after the delay.
                    MediaPendingDeletion::schedule($from, $old);

                    return;
                }

                DB::afterCommit(function () use ($from, $old): void {
                    try {
                        Storage::disk($from)->delete($old);
                    } catch (Throwable $e) {
                        report($e);
                    }
                });
            });
        } catch (Throwable $e) {
            $left = array_diff($written, $this->deleteCopies($destination, $written));
            // Copies that could not be removed keep their insurance, so the prune retries them.
            $this->dropInsurance($target, array_values(array_diff($insured, $left)));
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
     * The original's path on the target disk, keeping its extension; null when the row has none.
     */
    private function originalPath(Media $media, string $target, int $version): ?string
    {
        if ($media->original_path === null || $media->original_path === '') {
            return null;
        }

        return MediaPaths::original($target, $media->key, $version, pathinfo($media->original_path, PATHINFO_EXTENSION) ?: 'bin');
    }

    /**
     * Stream one object to the other disk (never loading it whole) and check the copy has the source's size.
     *
     * @throws RuntimeException when the source can't be read, the write fails or the sizes differ
     */
    private function copy(Filesystem $source, string $from, Filesystem $destination, string $target, string $to, string $mime): void
    {
        $stream = $source->readStream($from);

        if (! is_resource($stream)) {
            throw new RuntimeException("Could not read [{$from}] for the move.");
        }

        try {
            if ($destination->writeStream($to, $stream, MediaPaths::writeOptions($target, $mime)) === false) {
                throw new RuntimeException("Could not write [{$to}] on the [{$target}] disk.");
            }
        } finally {
            // The write may have closed the stream already (a closed resource's type is "Unknown").
            if (get_resource_type($stream) === 'stream') {
                fclose($stream);
            }
        }

        if ($destination->size($to) !== $source->size($from)) {
            throw new RuntimeException("The copy of [{$from}] on the [{$target}] disk has another size.");
        }
    }

    /**
     * Remove the copies a failed move wrote. A failed cleanup is reported; the original error matters more.
     *
     * @param  list<string>  $paths
     * @return list<string> The paths removed.
     */
    private function deleteCopies(Filesystem $disk, array $paths): array
    {
        $removed = [];

        foreach ($paths as $path) {
            try {
                if ($disk->delete($path)) {
                    $removed[] = $path;
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
     * @param  list<string>  $paths
     */
    private function dropInsurance(string $disk, array $paths): void
    {
        if ($paths !== []) {
            MediaPendingDeletion::query()->where('disk', $disk)->whereIn('path', $paths)->delete();
        }
    }
}

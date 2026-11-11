<?php

namespace App\Actions\Media;

use App\Enums\OptimizeOutcome;
use App\Models\Media;
use App\Models\User;
use App\Support\Media\ImageEdits;
use App\Support\Media\MediaSource;
use App\Support\Media\OptimizationRun;
use App\Support\Media\OptimizationRunStore;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * The engine of "Optimize existing files": walks the candidates of the current run by ascending id and rebuilds each
 * one with the current settings, in batches limited by a time budget. The browser, the queue job and the CLI all call
 * processBatch(); a cache lock lets only one batch run at a time.
 */
class OptimizeExistingMedia
{
    public const string LOCK = 'media-optimization-batch';

    public function __construct(
        private OptimizeImage $optimizer,
        private RebuildMediaFile $rebuilder,
        private OptimizationRunStore $runs,
    ) {}

    /**
     * Start a fresh run (replacing any existing one) as the given user.
     */
    public function start(User $actor): OptimizationRun
    {
        Gate::forUser($actor)->authorize('optimize', Media::class);

        return $this->startWithoutActor();
    }

    /**
     * Start a fresh run without an actor (the CLI), replacing any existing one.
     */
    public function startWithoutActor(): OptimizationRun
    {
        $fingerprint = $this->optimizer->fingerprint();

        $run = new OptimizationRun(
            status: 'running',
            fingerprint: $fingerprint,
            cursor: 0,
            total: Media::query()->optimizationCandidates($fingerprint)->count(),
            counts: [],
            bytesBefore: 0,
            bytesAfter: 0,
            startedAt: now()->toImmutable(),
            heartbeatAt: now()->toImmutable(),
        );

        $this->runs->save($run);

        return $run;
    }

    public function pause(): void
    {
        $run = $this->runs->get();

        if ($run?->status === 'running') {
            $run->status = 'paused';
            $this->runs->save($run);
        }
    }

    /**
     * Continue a paused run from its cursor. The heartbeat restarts, so a "queue isn't running" fallback counts from now.
     */
    public function resume(): void
    {
        $run = $this->runs->get();

        if ($run?->status === 'paused') {
            $run->status = 'running';
            $run->heartbeatAt = now()->toImmutable();
            $this->runs->save($run);
        }
    }

    public function stop(): void
    {
        $this->runs->clear();
    }

    /**
     * Process the next candidates after the cursor until $budgetSeconds are spent (checked after each file, so at least
     * one file is processed), then save the run. Does nothing while the run isn't running, is stale, optimization is
     * off or another batch holds the lock.
     *
     * @param  string  $driver  Who runs the batch: browser, queue or cli.
     * @return OptimizationRun|null The run after the batch; null when there is no run.
     */
    public function processBatch(string $driver, float $budgetSeconds): ?OptimizationRun
    {
        $run = $this->runs->get();

        if ($run === null || ! $this->canProcess($run)) {
            return $run;
        }

        $lock = Cache::lock(self::LOCK, (int) ceil($budgetSeconds) + 60);

        if (! $lock->get()) {
            return $run;
        }

        try {
            // Read again under the lock: the batch that held it may have moved the cursor.
            $run = $this->runs->get();

            if ($run === null || ! $this->canProcess($run)) {
                return $run;
            }

            $started = microtime(true);

            while (($media = $this->next($run)) !== null) {
                [$outcome, $before, $after] = $this->optimizeSafely($media);
                $run->record($media, $outcome, $before, $after);

                if (microtime(true) - $started >= $budgetSeconds) {
                    break;
                }
            }

            if ($this->next($run) === null) {
                $run->status = 'done';
            }

            $run->secondsSpent += microtime(true) - $started;
            $run->heartbeatAt = now()->toImmutable();
            $run->driver = $driver;

            return $this->save($run);
        } finally {
            $lock->release();
        }
    }

    /**
     * Rebuild one file from its source (the kept original, else the current file) with its stored edits.
     * Skipped outcomes write nothing; an existing original is never dropped.
     *
     * @return array{OptimizeOutcome, int, int} The outcome, and the size of the current file before and after.
     */
    public function optimizeOne(Media $media): array
    {
        $before = (int) $media->size;

        try {
            $source = MediaSource::for($media);
        } catch (RuntimeException) {
            return [OptimizeOutcome::Missing, $before, $before];
        }

        try {
            $edits = ImageEdits::fromArray($media->edits);
            $edits = $edits?->isEmpty() ? null : $edits;
            $result = $this->optimizer->handle($source->contents, $source->mime, $edits?->toArray());
        } catch (InvalidArgumentException) {
            return [OptimizeOutcome::Unreadable, $before, $before];
        }

        if ($result->failure !== null || in_array($result->mime, OptimizeImage::MUST_CONVERT, true)) {
            $outcome = $result->failure === 'too_large' ? OptimizeOutcome::TooLarge : OptimizeOutcome::Unreadable;

            return [$outcome, $before, $before];
        }

        if (! $source->isOriginal && $result->contents === $source->contents) {
            $this->rebuilder->markOptimized($media, $this->optimizer->fingerprint());

            return [OptimizeOutcome::AlreadyOptimal, $before, $before];
        }

        $keepOriginal = ! $source->isOriginal && config('media.optimize.enabled') && config('media.optimize.keep_originals');

        $this->rebuilder->store(
            $media,
            $result,
            $edits,
            newOriginal: $keepOriginal ? $source->contents : null,
            newOriginalMime: $keepOriginal ? $source->mime : null,
            keepEdits: true,
        );

        return [OptimizeOutcome::Optimized, $before, (int) $media->size];
    }

    /**
     * optimizeOne(), with an unexpected error (e.g. a failed disk write, already rolled back by RebuildMediaFile)
     * reported and counted as unreadable, so one bad file can't stop the run at the same cursor forever.
     *
     * @return array{OptimizeOutcome, int, int}
     */
    private function optimizeSafely(Media $media): array
    {
        try {
            return $this->optimizeOne($media);
        } catch (Throwable $e) {
            report($e);

            return [OptimizeOutcome::Unreadable, (int) $media->size, (int) $media->size];
        }
    }

    private function canProcess(OptimizationRun $run): bool
    {
        return $run->status === 'running'
            && ! $run->isStale($this->optimizer->fingerprint())
            && config('media.optimize.enabled');
    }

    private function next(OptimizationRun $run): ?Media
    {
        return Media::query()->optimizationCandidates($run->fingerprint)->where('id', '>', $run->cursor)->first();
    }

    /**
     * Save the batch's progress unless the run was stopped or replaced meanwhile; a pause made during the batch is kept.
     */
    private function save(OptimizationRun $run): ?OptimizationRun
    {
        $stored = $this->runs->get();

        if ($stored === null || ! $stored->startedAt->equalTo($run->startedAt)) {
            return $stored;
        }

        if ($stored->status === 'paused' && $run->status === 'running') {
            $run->status = 'paused';
        }

        $this->runs->save($run);

        return $run;
    }
}

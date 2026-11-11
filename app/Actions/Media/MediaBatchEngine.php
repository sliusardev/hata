<?php

namespace App\Actions\Media;

use App\Models\Media;
use App\Support\Media\MediaBatchRun;
use App\Support\Media\MediaBatchRunStore;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * The batch mechanics shared by "optimize existing files" and "move files to another disk" (decision 48): a run walks
 * its candidates by ascending id in batches limited by a time budget. The browser, the queue job and the CLI all call
 * processBatch(); a cache lock per mode lets only one batch of that mode run at a time. Before each file the run saves
 * the file's id as in progress and a fresh heartbeat; a file the process died on is not tried again in that run.
 *
 * @template TRun of MediaBatchRun
 */
abstract class MediaBatchEngine
{
    /** Pending cloud deletions handled at the end of a batch; kept small so the batch's response stays quick. */
    private const int PRUNE_PER_BATCH = 50;

    /**
     * @return MediaBatchRunStore<TRun>
     */
    abstract protected function runs(): MediaBatchRunStore;

    /**
     * The name of the cache lock a batch of this mode holds.
     */
    abstract protected function lockName(): string;

    /**
     * Whether a running run may still be processed, beyond its status (e.g. its settings are still current).
     *
     * @param  TRun  $run
     */
    abstract protected function isProcessable(MediaBatchRun $run): bool;

    /**
     * The next candidate after the run's cursor, if any.
     *
     * @param  TRun  $run
     */
    abstract protected function next(MediaBatchRun $run): ?Media;

    /**
     * Process one file and record its outcome on the run. Must not throw.
     *
     * @param  TRun  $run
     */
    abstract protected function processFile(MediaBatchRun $run, Media $media): void;

    /**
     * Record a file the process died on in an earlier batch, without touching it again.
     *
     * @param  TRun  $run
     */
    abstract protected function recordAbandoned(MediaBatchRun $run, Media $media): void;

    /**
     * Whether a running run must be paused now (checked before a batch and before each file), e.g. its feature was
     * turned off in the settings. A resume continues it.
     */
    protected function mustPause(): bool
    {
        return false;
    }

    /**
     * Seconds the batch lock outlives the budget: room for the file in progress when the budget runs out.
     */
    protected function fileSeconds(): int
    {
        return 60;
    }

    /**
     * Seconds a batch with the given budget holds the lock at most; a queue job of the mode must not outlive it.
     */
    public function lockSeconds(float $budgetSeconds): int
    {
        return (int) ceil($budgetSeconds) + $this->fileSeconds();
    }

    /**
     * The stored run of this mode, if any.
     *
     * @return TRun|null
     */
    public function current(): ?MediaBatchRun
    {
        return $this->runs()->get();
    }

    public function pause(): void
    {
        $run = $this->runs()->get();

        if ($run?->status === 'running') {
            $run->status = 'paused';
            $this->runs()->save($run);
        }
    }

    /**
     * Continue a paused run from its cursor. The heartbeat restarts, so a "queue isn't running" fallback counts from now.
     */
    public function resume(): void
    {
        $run = $this->runs()->get();

        if ($run?->status === 'paused') {
            $run->status = 'running';
            $run->heartbeatAt = now()->toImmutable();
            $this->runs()->save($run);
        }
    }

    public function stop(): void
    {
        $this->runs()->clear();
    }

    /**
     * Whether a batch holds the lock right now. Probes by taking the lock and releasing it at once; a batch that
     * starts in that instant finds it taken and backs off (the queue job then retries after a short delay).
     */
    public function isBatchRunning(): bool
    {
        return Cache::lock($this->lockName(), 5)->get(fn () => true) !== true;
    }

    /**
     * Whether a batch would process the run now: it is running, still current and nothing asks to pause it.
     *
     * @param  TRun  $run
     */
    public function canProcess(MediaBatchRun $run): bool
    {
        return $run->status === 'running' && $this->isProcessable($run) && ! $this->mustPause();
    }

    /**
     * Process the next candidates after the cursor until $budgetSeconds are spent (checked after each file, so at least
     * one file is processed), then save the run. Does nothing while the run isn't running, is stale or another batch
     * holds the lock. A running run that must pause is paused (before the batch or before its next file).
     *
     * @param  string  $driver  Who runs the batch: browser, queue or cli.
     * @return TRun|null The run after the batch; null when there is no run.
     */
    public function processBatch(string $driver, float $budgetSeconds): ?MediaBatchRun
    {
        $run = $this->runs()->get();

        if ($run?->status === 'running' && $this->mustPause()) {
            // E.g. turned off during the run: pause it, so every driver stops and the page shows it; a resume continues.
            $this->pause();

            return $this->runs()->get();
        }

        if ($run === null || ! $this->canProcess($run)) {
            return $run;
        }

        $lock = Cache::lock($this->lockName(), $this->lockSeconds($budgetSeconds));

        if (! $lock->get()) {
            return $run;
        }

        try {
            // Read again under the lock: the batch that held it may have moved the cursor.
            $run = $this->runs()->get();

            if ($run === null || ! $this->canProcess($run)) {
                return $run;
            }

            $started = microtime(true);
            $savedCursor = $run->cursor;

            while (($media = $this->next($run)) !== null) {
                if ($media->id === $run->inProgress) {
                    // The process died on this file in an earlier batch (e.g. a memory fatal): don't try it again in this run.
                    $run->inProgress = null;
                    $this->recordAbandoned($run, $media);
                } elseif ($this->mustPause()) {
                    // E.g. turned off during the batch (the queue re-applies the settings per job, the CLI per batch).
                    $run->status = 'paused';

                    break;
                } else {
                    $run->inProgress = $media->id;
                    $run->heartbeatAt = now()->toImmutable();
                    $saved = $this->save($run, $savedCursor);

                    if ($saved !== $run) {
                        return $saved;
                    }

                    if ($run->status !== 'running') {
                        // Paused meanwhile: leave this file for the resume.
                        $run->inProgress = null;

                        break;
                    }

                    $this->processFile($run, $media);
                    $run->inProgress = null;
                }

                if ($run->status !== 'running' || microtime(true) - $started >= $budgetSeconds) {
                    break;
                }
            }

            if ($run->status === 'running' && $this->next($run) === null) {
                $run->status = 'done';
            }

            $run->secondsSpent += microtime(true) - $started;
            $run->heartbeatAt = now()->toImmutable();
            $run->driver = $driver;

            $saved = $this->save($run, $savedCursor);

            // Only while the batch has budget left, so a browser tick that already used it answers at once.
            if (microtime(true) - $started < $budgetSeconds) {
                $this->pruneDeletions();
            }

            return $saved;
        } finally {
            $lock->release();
        }
    }

    /**
     * Remove replaced cloud objects whose delay is over, a few per batch, so they go even without a scheduler.
     * A failure here never fails the batch.
     */
    private function pruneDeletions(): void
    {
        try {
            app(PruneMediaDeletions::class)->handle(self::PRUNE_PER_BATCH);
        } catch (Throwable $e) {
            report($e);
        }
    }

    /**
     * Save the batch's progress unless the run was stopped or replaced meanwhile, or another batch moved its cursor
     * past the one this batch last saved (the lock expired mid-batch). A pause made during the batch is kept.
     *
     * @param  TRun  $run
     * @return TRun|null $run when saved, else the stored run (null when stopped)
     */
    private function save(MediaBatchRun $run, int &$savedCursor): ?MediaBatchRun
    {
        $stored = $this->runs()->get();

        if ($stored === null || $stored->id !== $run->id || $stored->cursor > $savedCursor) {
            return $stored;
        }

        if ($stored->status === 'paused' && $run->status === 'running') {
            $run->status = 'paused';
        }

        $this->runs()->save($run);
        $savedCursor = $run->cursor;

        return $run;
    }
}

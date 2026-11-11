<?php

namespace App\Jobs;

use App\Actions\Media\MediaBatchEngine;
use App\Support\Media\MediaBatchRun;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * One time-boxed batch of a media run on a queue worker. While the run can go on it queues the next batch. Not unique:
 * the engine's batch lock keeps two batches of a mode from running at once. Each job carries the id of the run it was
 * queued for and stops when the stored run is another one, so the chain of a stopped run dies out.
 *
 * @template TRun of MediaBatchRun
 */
abstract class MediaBatchJob implements ShouldQueue
{
    use Queueable;

    /** Seconds a batch may keep starting new files; the timeout leaves room for the file in progress. */
    public const float BUDGET = 45.0;

    /** Seconds to wait before the next batch when the last one couldn't run (lock held by another batch). */
    public const int WAIT_SECONDS = 10;

    public int $timeout = 60;

    public int $tries = 1;

    public function __construct(public string $runId) {}

    /**
     * @return MediaBatchEngine<TRun>
     */
    abstract protected function engine(): MediaBatchEngine;

    /**
     * Queue the next batch of the run, named by its class (`ClassName::dispatch()`, not `self::`/`static::`, which would
     * forward an anonymous subclass).
     */
    abstract protected function queueNext(?CarbonInterface $delay): void;

    public function handle(): void
    {
        $engine = $this->engine();
        $before = $engine->current();

        if ($before?->id !== $this->runId) {
            return;
        }

        $run = $engine->processBatch('queue', static::BUDGET);

        // The engine pauses a run that must pause; never come back for one that can't go on or was replaced.
        if ($run === null || $run->id !== $this->runId || ! $engine->canProcess($run)) {
            return;
        }

        // Nothing moved: another batch holds the lock. Come back later instead of flooding the queue.
        $moved = $run->toArray() !== $before->toArray();

        $this->queueNext($moved ? null : now()->addSeconds(self::WAIT_SECONDS));
    }
}

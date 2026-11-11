<?php

namespace App\Jobs;

use App\Actions\Media\OptimizeExistingMedia;
use App\Actions\Media\OptimizeImage;
use App\Support\Media\OptimizationRunStore;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * One time-boxed batch of "Optimize existing files" on a queue worker. While candidates are left it queues the next
 * batch. Not unique: the engine's batch lock keeps two batches from running at once. Each job carries the id of the
 * run it was queued for and stops when the stored run is another one, so the chain of a stopped run dies out.
 */
class OptimizeExistingMediaBatch implements ShouldQueue
{
    use Queueable;

    /** Seconds a batch may keep starting new files; the timeout leaves room for the file in progress. */
    public const float BUDGET = 45.0;

    /** Seconds to wait before the next batch when the last one couldn't run (lock held by another batch). */
    public const int WAIT_SECONDS = 10;

    public int $timeout = 60;

    public int $tries = 1;

    public function __construct(public string $runId) {}

    public function handle(OptimizeExistingMedia $engine, OptimizeImage $optimizer, OptimizationRunStore $runs): void
    {
        $before = $runs->get();

        if ($before?->id !== $this->runId) {
            return;
        }

        $run = $engine->processBatch('queue', static::BUDGET);

        // The engine pauses a run that finds optimization off; never come back for one that can't move or was replaced.
        if ($run === null || $run->id !== $this->runId || $run->status !== 'running' || $run->isStale($optimizer->fingerprint()) || ! config('media.optimize.enabled')) {
            return;
        }

        // Nothing moved: another batch holds the lock. Come back later instead of flooding the queue.
        $moved = $run->toArray() !== $before->toArray();

        OptimizeExistingMediaBatch::dispatch($this->runId)->delay($moved ? null : now()->addSeconds(self::WAIT_SECONDS));
    }
}

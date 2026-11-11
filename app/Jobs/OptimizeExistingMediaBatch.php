<?php

namespace App\Jobs;

use App\Actions\Media\OptimizeExistingMedia;
use App\Actions\Media\OptimizeImage;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * One time-boxed batch of "Optimize existing files" on a queue worker. While candidates are left it queues the next
 * batch. Not unique: the engine's batch lock keeps two batches from running at once.
 */
class OptimizeExistingMediaBatch implements ShouldQueue
{
    use Queueable;

    /** Seconds a batch may keep starting new files; the timeout leaves room for the file in progress. */
    public const float BUDGET = 45.0;

    public int $timeout = 60;

    public int $tries = 1;

    public function handle(OptimizeExistingMedia $engine, OptimizeImage $optimizer): void
    {
        $run = $engine->processBatch('queue', static::BUDGET);

        if ($run !== null && $run->status === 'running' && ! $run->isStale($optimizer->fingerprint())) {
            OptimizeExistingMediaBatch::dispatch();
        }
    }
}

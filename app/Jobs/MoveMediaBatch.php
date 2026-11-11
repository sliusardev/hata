<?php

namespace App\Jobs;

use App\Actions\Media\MoveMediaToDisk;
use App\Support\Media\DiskMoveRun;
use Carbon\CarbonInterface;

/**
 * One time-boxed batch of "Move files to another disk" on a queue worker (see MediaBatchJob).
 *
 * @extends MediaBatchJob<DiskMoveRun>
 */
class MoveMediaBatch extends MediaBatchJob
{
    protected function engine(): MoveMediaToDisk
    {
        return app(MoveMediaToDisk::class);
    }

    protected function queueNext(?CarbonInterface $delay): void
    {
        MoveMediaBatch::dispatch($this->runId)->delay($delay);
    }
}

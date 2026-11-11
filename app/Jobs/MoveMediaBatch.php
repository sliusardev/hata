<?php

namespace App\Jobs;

use App\Actions\Media\MoveMediaToDisk;
use App\Support\Media\DiskMoveRun;
use Carbon\CarbonInterface;

/**
 * One time-boxed batch of "Move files to another disk" on a queue worker (see MediaBatchJob). Its timeout is the
 * engine's batch lock (the budget plus MoveMediaToDisk::FILE_SECONDS), so one very large file can finish its copy.
 *
 * @extends MediaBatchJob<DiskMoveRun>
 */
class MoveMediaBatch extends MediaBatchJob
{
    public function __construct(string $runId)
    {
        parent::__construct($runId);

        $this->timeout = $this->engine()->lockSeconds(self::BUDGET);
    }

    protected function engine(): MoveMediaToDisk
    {
        return app(MoveMediaToDisk::class);
    }

    protected function queueNext(?CarbonInterface $delay): void
    {
        MoveMediaBatch::dispatch($this->runId)->delay($delay);
    }
}

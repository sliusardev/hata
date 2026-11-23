<?php

namespace Hata\Jobs;

use Carbon\CarbonInterface;
use Hata\Actions\Media\MoveMediaToDisk;
use Hata\Support\Media\DiskMoveRun;

use function Illuminate\Support\enum_value;

/**
 * One time-boxed batch of "Move files to another disk" on a queue worker (see MediaBatchJob). Its timeout is the
 * engine's queue batch lock: the budget plus a file's time, clamped below the job connection's retry_after so a second
 * worker is never handed a job that still runs. A file that outlives it is killed and abandoned by the next batch;
 * very large files go through `media:move-disk`.
 *
 * @extends MediaBatchJob<DiskMoveRun>
 */
class MoveMediaBatch extends MediaBatchJob
{
    public function __construct(string $runId)
    {
        parent::__construct($runId);

        $this->timeout = $this->timeoutFor($this->connection);
    }

    /**
     * Recompute the timeout for the connection the job is sent to (the payload is built from it when dispatched).
     */
    public function onConnection($connection)
    {
        $this->timeout = $this->timeoutFor(enum_value($connection));

        return parent::onConnection($connection);
    }

    private function timeoutFor(?string $connection): int
    {
        return $this->engine()->lockSeconds(self::BUDGET, 'queue', $connection);
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

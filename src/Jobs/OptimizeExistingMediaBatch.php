<?php

namespace App\Jobs;

use App\Actions\Media\OptimizeExistingMedia;
use App\Support\Media\OptimizationRun;
use Carbon\CarbonInterface;

/**
 * One time-boxed batch of "Optimize existing files" on a queue worker (see MediaBatchJob). The chain also stops once
 * the run is stale or optimization was turned off.
 *
 * @extends MediaBatchJob<OptimizationRun>
 */
class OptimizeExistingMediaBatch extends MediaBatchJob
{
    protected function engine(): OptimizeExistingMedia
    {
        return app(OptimizeExistingMedia::class);
    }

    protected function queueNext(?CarbonInterface $delay): void
    {
        OptimizeExistingMediaBatch::dispatch($this->runId)->delay($delay);
    }
}

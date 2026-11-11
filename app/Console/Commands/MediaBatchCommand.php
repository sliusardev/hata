<?php

namespace App\Console\Commands;

use App\Actions\Media\MediaBatchEngine;
use App\Support\Media\MediaBatchRun;
use App\Support\Settings\SiteSettings;
use Illuminate\Console\Command;
use Illuminate\Support\Sleep;

/**
 * The CLI driver of a media run: processes batches until the run is done, paused or can't go on, printing a line per
 * batch, and waits (up to two minutes) while another batch of the same mode holds the lock.
 */
abstract class MediaBatchCommand extends Command
{
    protected const float BATCH_SECONDS = 5.0;

    /** How many 1-second waits without progress (another batch holds the lock) before giving up. */
    protected const int MAX_IDLE_WAITS = 120;

    /**
     * The line printed after each batch that moved the run.
     */
    abstract protected function progressLine(MediaBatchRun $run): string;

    /**
     * The exit code when the run stopped in a way that is an error (checked after each batch), else null.
     */
    protected function failure(MediaBatchRun $run): ?int
    {
        return null;
    }

    /**
     * Drive the run with batches until it stops.
     *
     * @template TRun of MediaBatchRun
     *
     * @param  MediaBatchEngine<TRun>  $engine
     * @param  TRun  $run
     * @return TRun|int|null The run when it stopped (null when it was stopped elsewhere), or an exit code on failure.
     */
    protected function processBatches(MediaBatchEngine $engine, MediaBatchRun $run, SiteSettings $settings): MediaBatchRun|int|null
    {
        $idle = 0;

        while (true) {
            // The settings live in the database and may be changed in the admin while this runs.
            $settings->apply();

            $before = $run->toArray();
            $run = $engine->processBatch('cli', static::BATCH_SECONDS);

            if ($run === null) {
                return null;
            }

            if (($code = $this->failure($run)) !== null) {
                return $code;
            }

            if ($engine->canProcess($run) && $run->toArray() === $before) {
                if (++$idle >= static::MAX_IDLE_WAITS) {
                    $this->components->error('Another batch is running; try again later.');

                    return self::FAILURE;
                }

                Sleep::for(1)->second();

                continue;
            }

            $idle = 0;
            $this->line($this->progressLine($run));

            if (! $engine->canProcess($run)) {
                return $run;
            }
        }
    }
}

<?php

namespace Hata\Console\Commands;

use Hata\Actions\Media\OptimizeExistingMedia;
use Hata\Actions\Media\OptimizeImage;
use Hata\Enums\OptimizeOutcome;
use Hata\Exceptions\OptimizationDisabledException;
use Hata\Support\Media\MediaBatchRun;
use Hata\Support\Media\OptimizationRun;
use Hata\Support\Media\OptimizationRunStore;
use Hata\Support\Settings\SiteSettings;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;

#[Signature('media:optimize {--restart : Discard the current run and start over}')]
#[Description('Re-optimize existing images with the current media settings')]
class OptimizeMedia extends MediaBatchCommand
{
    public function handle(OptimizeExistingMedia $engine, OptimizationRunStore $runs, OptimizeImage $optimizer, SiteSettings $settings): int
    {
        if (! $this->optimizationEnabled()) {
            $this->components->error('Optimization is off in the media settings.');

            return self::FAILURE;
        }

        $run = $this->option('restart') ? null : $runs->get();

        if ($run !== null && in_array($run->status, ['running', 'paused'], true) && ! $run->isStale($optimizer->fingerprint())) {
            $engine->resume();
            $this->components->info('Resuming the current run.');
        } else {
            try {
                $run = $engine->startWithoutActor();
            } catch (OptimizationDisabledException) {
                $this->components->error('Optimization is off in the media settings.');

                return self::FAILURE;
            }

            $this->components->info("Starting a new run: {$run->total} file(s) to optimize.");
        }

        $run = $this->processBatches($engine, $run, $settings);

        if (is_int($run)) {
            return $run;
        }

        if ($run !== null) {
            $this->summary($run);
        }

        return self::SUCCESS;
    }

    protected function progressLine(MediaBatchRun $run): string
    {
        assert($run instanceof OptimizationRun);

        return sprintf(
            'Processed %d/%d · saved %s · skipped %d',
            $run->processed(),
            $run->total,
            $this->megabytes($run->bytesSaved()),
            $run->skipped(),
        );
    }

    /**
     * The engine paused the run because optimization was turned off; resuming continues it.
     */
    protected function failure(MediaBatchRun $run): ?int
    {
        if ($run->status === 'paused' && ! $this->optimizationEnabled()) {
            $this->components->error('Optimization is off in the media settings.');

            return self::FAILURE;
        }

        return null;
    }

    /**
     * Read on every call: the settings are re-applied before each batch and may have changed.
     */
    private function optimizationEnabled(): bool
    {
        return (bool) config('media.optimize.enabled');
    }

    private function megabytes(int $bytes): string
    {
        return sprintf('%+.2f MB', $bytes / 1048576);
    }

    private function summary(OptimizationRun $run): void
    {
        $this->newLine();
        $this->components->info(match (true) {
            $run->status === 'done' => 'Done.',
            $run->status === 'paused' => 'The run was paused.',
            default => 'The run stopped: the media settings changed. Run the command again.',
        });

        $this->table(
            ['Outcome', 'Files'],
            array_map(fn (OptimizeOutcome $outcome) => [$outcome->value, $run->counts[$outcome->value] ?? 0], OptimizeOutcome::cases()),
        );
        $this->line(sprintf('Saved %s (%d -> %d bytes).', $this->megabytes($run->bytesSaved()), $run->bytesBefore, $run->bytesAfter));
    }
}

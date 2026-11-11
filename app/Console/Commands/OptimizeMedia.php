<?php

namespace App\Console\Commands;

use App\Actions\Media\OptimizeExistingMedia;
use App\Actions\Media\OptimizeImage;
use App\Enums\OptimizeOutcome;
use App\Exceptions\OptimizationDisabledException;
use App\Support\Media\OptimizationRun;
use App\Support\Media\OptimizationRunStore;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('media:optimize {--restart : Discard the current run and start over}')]
#[Description('Re-optimize existing images with the current media settings')]
class OptimizeMedia extends Command
{
    private const float BATCH_SECONDS = 5.0;

    public function handle(OptimizeExistingMedia $engine, OptimizationRunStore $runs, OptimizeImage $optimizer): int
    {
        if (! config('media.optimize.enabled')) {
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

        while (($run = $engine->processBatch('cli', self::BATCH_SECONDS)) !== null) {
            $this->line(sprintf(
                'Processed %d/%d · saved %s · skipped %d',
                $run->processed(),
                $run->total,
                $this->megabytes($run->bytesBefore - $run->bytesAfter),
                $this->skipped($run),
            ));

            if ($run->status !== 'running' || $run->isStale($optimizer->fingerprint())) {
                break;
            }
        }

        if ($run !== null) {
            $this->summary($run);
        }

        return self::SUCCESS;
    }

    private function skipped(OptimizationRun $run): int
    {
        return $run->processed() - ($run->counts[OptimizeOutcome::Optimized->value] ?? 0);
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
        $this->line(sprintf('Saved %s (%d -> %d bytes).', $this->megabytes($run->bytesBefore - $run->bytesAfter), $run->bytesBefore, $run->bytesAfter));
    }
}

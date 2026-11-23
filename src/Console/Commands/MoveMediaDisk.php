<?php

namespace Hata\Console\Commands;

use Hata\Actions\Media\MoveMediaToDisk;
use Hata\Enums\MoveOutcome;
use Hata\Support\Media\DiskMoveRun;
use Hata\Support\Media\DiskMoveRunStore;
use Hata\Support\Media\MediaBatchRun;
use Hata\Support\Settings\SiteSettings;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use InvalidArgumentException;

#[Signature('media:move-disk {to : Where to move the files: public (this server: images public, the rest private) or s3} {--restart : Discard the current move and start over}')]
#[Description('Move media files between this server and the cloud disk')]
class MoveMediaDisk extends MediaBatchCommand
{
    public function handle(MoveMediaToDisk $engine, DiskMoveRunStore $runs, SiteSettings $settings): int
    {
        $target = (string) $this->argument('to');
        $run = $this->option('restart') ? null : $runs->get();

        if ($run !== null && $run->target === $target && in_array($run->status, ['running', 'paused'], true)) {
            $engine->resume();
            $this->components->info('Resuming the current move.');
        } else {
            try {
                $run = $engine->startWithoutActor($target);
            } catch (InvalidArgumentException $e) {
                $this->components->error($e->getMessage());

                return self::FAILURE;
            }

            $storage = __("hata::site_settings.media.storage.{$target}", locale: 'en');
            $this->components->info("Starting a new move to {$storage} ({$target}): {$run->total} file(s) to move.");
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
        assert($run instanceof DiskMoveRun);

        return sprintf('Moved %d/%d · %.2f MB · skipped %d', $run->processed(), $run->total, $run->bytesMoved / 1048576, $run->skipped());
    }

    private function summary(DiskMoveRun $run): void
    {
        $this->newLine();
        $this->components->info($run->status === 'done' ? 'Done.' : 'The move was paused.');

        $this->table(
            ['Outcome', 'Files'],
            array_map(fn (MoveOutcome $outcome) => [$outcome->value, $run->counts[$outcome->value] ?? 0], MoveOutcome::cases()),
        );
    }
}

<?php

namespace Hata\Console\Commands;

use Hata\Actions\Media\PruneMediaDeletions as PruneAction;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('media:prune-deleted')]
#[Description('Delete the replaced cloud media files whose 7-day delay is over')]
class PruneMediaDeletions extends Command
{
    public function handle(PruneAction $prune): int
    {
        $total = 0;

        do {
            $removed = $prune->handle();
            $total += $removed;
        } while ($removed > 0);

        $this->components->info("Removed {$total} replaced file(s).");

        return self::SUCCESS;
    }
}

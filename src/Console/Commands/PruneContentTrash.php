<?php

namespace App\Console\Commands;

use App\Actions\Content\PruneEntryTrash;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('content:prune-trash')]
#[Description('Delete for good the content records whose time in the trash is over')]
class PruneContentTrash extends Command
{
    public function handle(PruneEntryTrash $prune): int
    {
        $removed = $prune->handle();

        $this->components->info("Removed {$removed} trashed record(s).");

        return self::SUCCESS;
    }
}

<?php

namespace Hata\Console\Commands;

use Hata\Models\Entry;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;

/**
 * A record's search index is written when the record is saved, so a field made searchable later (or a type that
 * starts searching its excerpt) is found only in records saved since. This rebuilds the index of every stored
 * record, trashed ones too, and leaves their dates alone. Safe to run again: only records whose index changed are
 * written.
 */
#[Signature('content:reindex-search')]
#[Description('Rebuild the search index of the content records, after a field was made searchable')]
class ReindexContentSearch extends Command
{
    public function handle(): int
    {
        $checked = 0;
        $updated = 0;

        Entry::withTrashed()->with('group')->chunkById(500, function (Collection $entries) use (&$checked, &$updated): void {
            foreach ($entries as $entry) {
                $checked++;
                $updated += $entry->refreshSearchIndex() ? 1 : 0;
            }
        });

        $this->components->info("Updated the search index of {$updated} of {$checked} records.");

        return self::SUCCESS;
    }
}

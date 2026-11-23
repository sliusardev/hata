<?php

namespace Hata\Actions\Content;

use Hata\Models\Entry;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Deletes for good the records trashed more than `content.trash_days` days ago, in chunks, each in its own
 * transaction, together with every trashed record under their paths (DeleteEntry::purge()), so no trashed record is
 * left under a parent that is gone. No actor (decision 16): it runs from `content:prune-trash`, scheduled daily.
 */
class PruneEntryTrash
{
    private const int CHUNK = 200;

    public function __construct(private readonly DeleteEntry $delete) {}

    /**
     * @return int How many records were removed.
     */
    public function handle(): int
    {
        $removed = 0;
        $cutoff = now()->subDays((int) config('hata.content.trash_days'));

        Entry::onlyTrashed()
            ->where('deleted_at', '<', $cutoff)
            ->select(['id', 'type', 'locale', 'path'])
            ->chunkById(self::CHUNK, function (Collection $entries) use (&$removed): void {
                $removed += DB::transaction(fn (): int => $this->delete->purge($entries));
            });

        return $removed;
    }
}

<?php

namespace App\Actions\Content;

use App\Models\Entry;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Deletes for good the records trashed more than `content.trash_days` days ago, in chunks, each in its own
 * transaction. No actor (decision 16): it runs from `content:prune-trash`, scheduled daily. A record's descendants
 * were trashed with it or before it, so they are never younger than the record and go in the same run or an
 * earlier one.
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
        $cutoff = now()->subDays((int) config('content.trash_days'));

        Entry::onlyTrashed()
            ->where('deleted_at', '<', $cutoff)
            ->select(['id', 'entry_group_id'])
            ->chunkById(self::CHUNK, function (Collection $entries) use (&$removed): void {
                $removed += DB::transaction(fn (): int => $this->delete->purge($entries));
            });

        return $removed;
    }
}

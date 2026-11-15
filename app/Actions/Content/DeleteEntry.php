<?php

namespace App\Actions\Content;

use App\Exceptions\ProtectedActionException;
use App\Models\Entry;
use App\Models\EntryGroup;
use App\Models\MediaUsage;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class DeleteEntry
{
    /**
     * Deletes a record in the trash for good, with every trashed record under its path (same type and language),
     * whenever each was trashed: none may be left with a path under a prefix that is free again. Nothing live can be
     * under a trashed record (trashing takes the live descendants along, and a trashed record is never a valid
     * parent), and only trashed rows are touched. The redirects go with the records (foreign key), their media
     * usages are removed, and a group left without records goes too, with its usages.
     *
     * @throws ProtectedActionException when the record is not in the trash
     */
    public function handle(User $actor, Entry $entry): void
    {
        Gate::forUser($actor)->authorize('forceDelete', $entry);

        if (! $entry->trashed()) {
            throw ProtectedActionException::because('content.entry.not_trashed');
        }

        DB::transaction(fn (): int => $this->purge($entry->newCollection([$entry])));

        $entry->exists = false;
    }

    /**
     * Removes trashed records for good with every trashed record under their paths, without authorization (for
     * this action and PruneEntryTrash, inside their transaction): their media usages, the rows (redirects follow by
     * foreign key), then the groups left without any record, trashed ones counted, with their usages. Six queries
     * whatever the number of records.
     *
     * The selection locks its rows (`lockForUpdate()`), and the usage and row deletes are limited to rows still in
     * the trash, so a record another request restored before the lock was taken (or, on SQLite, which has no row
     * locks, before this transaction's first write) is left alive with its usages and redirects.
     *
     * @param  Collection<int, Entry>  $records  Trashed records with at least `id`, `type`, `locale` and `path`.
     * @return int How many records were removed, those under them included.
     */
    public function purge(Collection $records): int
    {
        if ($records->isEmpty()) {
            return 0;
        }

        $entries = Entry::onlyTrashed()
            ->where(function (Builder $query) use ($records): void {
                $query->whereKey($records->modelKeys());

                foreach ($records as $record) {
                    $query->orWhere(fn (Builder $query) => $query->under($record->type, $record->locale, $record->path));
                }
            })
            ->lockForUpdate()
            ->get(['id', 'entry_group_id']);

        $ids = $entries->modelKeys();
        $groupIds = $entries->pluck('entry_group_id')->unique()->values()->all();

        MediaUsage::query()
            ->where('usable_type', (new Entry)->getMorphClass())
            ->whereIn('usable_id', Entry::onlyTrashed()->whereKey($ids)->select('id'))
            ->delete();

        $removed = (int) Entry::onlyTrashed()->whereKey($ids)->forceDelete();

        $emptied = EntryGroup::query()
            ->whereKey($groupIds)
            ->whereDoesntHave('entries', fn (Builder $query) => $query->withTrashed())
            ->pluck('id')
            ->all();

        if ($emptied !== []) {
            MediaUsage::query()->where('usable_type', (new EntryGroup)->getMorphClass())->whereIn('usable_id', $emptied)->delete();
            EntryGroup::query()->whereKey($emptied)->delete();
        }

        return $removed;
    }
}

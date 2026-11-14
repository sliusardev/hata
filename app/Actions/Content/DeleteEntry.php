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
     * Deletes a record in the trash for good, with the descendants trashed at the same instant (what RestoreEntry
     * would bring back with it). A descendant trashed earlier on its own stays in the trash and is restored at the
     * top level later. The redirects go with the records (foreign key), their media usages are removed, and a group
     * left without records goes too, with its usages.
     *
     * @throws ProtectedActionException when the record is not in the trash
     */
    public function handle(User $actor, Entry $entry): void
    {
        Gate::forUser($actor)->authorize('forceDelete', $entry);

        if (! $entry->trashed()) {
            throw ProtectedActionException::because('content.entry.not_trashed');
        }

        DB::transaction(function () use ($entry): void {
            $entries = Entry::onlyTrashed()
                ->where('type', $entry->type)
                ->where('locale', $entry->locale)
                ->where(fn (Builder $query) => $query
                    ->whereKey($entry->getKey())
                    ->orWhere(fn (Builder $query) => $query->where('path', 'like', $entry->path.'/%')->where('deleted_at', $entry->deleted_at)))
                ->get(['id', 'entry_group_id']);

            $this->purge($entries);
        });

        $entry->exists = false;
    }

    /**
     * Removes the records for good, without authorization (for this action and PruneEntryTrash, inside their
     * transaction): their media usages, the rows (redirects follow by foreign key), then the groups left without any
     * record, trashed ones counted, with their usages. Five queries whatever the number of records.
     *
     * @param  Collection<int, Entry>  $entries  The records with at least `id` and `entry_group_id`.
     * @return int How many records were removed.
     */
    public function purge(Collection $entries): int
    {
        if ($entries->isEmpty()) {
            return 0;
        }

        $ids = $entries->modelKeys();
        $groupIds = $entries->pluck('entry_group_id')->unique()->values()->all();

        MediaUsage::query()->where('usable_type', (new Entry)->getMorphClass())->whereIn('usable_id', $ids)->delete();

        $removed = (int) Entry::withTrashed()->whereKey($ids)->forceDelete();

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

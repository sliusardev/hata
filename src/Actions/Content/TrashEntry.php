<?php

namespace App\Actions\Content;

use App\Models\Entry;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class TrashEntry
{
    /**
     * Moves the record and its live descendants to the trash with one shared `deleted_at`, so RestoreEntry can
     * tell what was trashed together. One query whatever the number of descendants. Paths stay as they are (and
     * stay taken) while the records are in the trash.
     *
     * @return int How many records were trashed; 0 when the record already was.
     */
    public function handle(User $actor, Entry $entry): int
    {
        Gate::forUser($actor)->authorize('delete', $entry);

        if ($entry->trashed()) {
            return 0;
        }

        // Stored to the second, so the in-memory value matches the column RestoreEntry compares with.
        $now = now()->startOfSecond();

        $count = DB::transaction(fn (): int => Entry::query()
            ->where('type', $entry->type)
            ->where('locale', $entry->locale)
            ->where(fn (Builder $query) => $query->whereKey($entry->getKey())->orWhere('path', 'like', $entry->path.'/%'))
            ->update(['deleted_at' => $now]));

        $entry->setAttribute('deleted_at', $now);
        $entry->syncOriginalAttribute('deleted_at');

        return $count;
    }
}

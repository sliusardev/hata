<?php

namespace App\Actions\Content;

use App\Models\Entry;
use App\Models\User;
use App\Support\Content\EntryPaths;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class RestoreEntry
{
    public function __construct(private readonly EntryPaths $paths) {}

    /**
     * Takes the record out of the trash with the descendants trashed at the same instant (the same `deleted_at`);
     * a descendant trashed earlier on its own stays there. A record whose parent is in the trash or gone becomes
     * top level: its slug gets a suffix when that path is taken or reserved, and its descendants' paths follow
     * (EntryPaths::move(), no redirects). A constant number of queries whatever the number of descendants.
     */
    public function handle(User $actor, Entry $entry): Entry
    {
        Gate::forUser($actor)->authorize('restore', $entry);

        if (! $entry->trashed()) {
            return $entry;
        }

        return DB::transaction(function () use ($entry): Entry {
            Entry::onlyTrashed()
                ->where('type', $entry->type)
                ->where('locale', $entry->locale)
                ->where(fn (Builder $query) => $query
                    ->whereKey($entry->getKey())
                    ->orWhere(fn (Builder $query) => $query->where('path', 'like', $entry->path.'/%')->where('deleted_at', $entry->deleted_at)))
                ->update(['deleted_at' => null]);

            $entry->setAttribute('deleted_at', null);
            $entry->syncOriginalAttribute('deleted_at');

            if ($this->lostItsParent($entry)) {
                $type = $entry->type();
                $slug = $this->paths->suggest($type, $entry->locale, null, $entry->slug, $entry);

                $entry->slug = $slug;
                $entry->parent_id = null;
                $this->paths->move($entry, $this->paths->pathFor($type, null, $slug), keepOldPaths: false);
            }

            return $entry;
        });
    }

    /**
     * Below the top level while its parent is in the trash or deleted for good (which nulls `parent_id`).
     */
    private function lostItsParent(Entry $entry): bool
    {
        if ($entry->depth() === 0) {
            return false;
        }

        return $entry->parent_id === null || ! Entry::query()->whereKey($entry->parent_id)->exists();
    }
}

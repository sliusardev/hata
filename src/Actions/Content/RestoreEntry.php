<?php

namespace Hata\Actions\Content;

use App\Models\User;
use Hata\Exceptions\ProtectedActionException;
use Hata\Models\Entry;
use Hata\Support\Content\EntryPaths;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class RestoreEntry
{
    /** Tries at a free top-level path before giving up. */
    private const int ATTEMPTS = 2;

    public function __construct(private readonly EntryPaths $paths) {}

    /**
     * Takes the record out of the trash with the descendants trashed at the same instant (the same `deleted_at`);
     * a descendant trashed earlier on its own stays there. A record whose parent is in the trash or gone becomes
     * top level: its slug gets a suffix when that path is taken or reserved, and its descendants' paths follow
     * (EntryPaths::move(), no redirects). A constant number of queries whatever the number of descendants.
     *
     * @throws ProtectedActionException when other requests keep taking the record's new top-level path
     */
    public function handle(User $actor, Entry $entry): Entry
    {
        Gate::forUser($actor)->authorize('restore', $entry);

        if (! $entry->trashed()) {
            return $entry;
        }

        $trashedAt = $entry->deleted_at;

        try {
            return $this->restore($entry);
        } catch (ProtectedActionException $exception) {
            // The writes were rolled back: the record the caller holds is in the trash again too.
            $entry->setAttribute('deleted_at', $trashedAt);
            $entry->syncOriginalAttribute('deleted_at');

            throw $exception;
        }
    }

    /**
     * @throws ProtectedActionException
     */
    private function restore(Entry $entry): Entry
    {
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
                $this->moveToTopLevel($entry);
            }

            return $entry;
        });
    }

    /**
     * Gives the record a free top-level path. When another request takes the suggested path between the check and
     * the write (the unique index refuses it), the writes are undone to their savepoint and tried once more with a
     * fresh suggestion; a second refusal ends the restore.
     *
     * @throws ProtectedActionException when the path is taken twice in a row
     */
    private function moveToTopLevel(Entry $entry): void
    {
        $type = $entry->type();
        $before = ['slug' => $entry->slug, 'path' => $entry->path, 'parent_id' => $entry->parent_id];

        for ($attempt = 1; $attempt <= self::ATTEMPTS; $attempt++) {
            try {
                $slug = $this->paths->suggest($type, $entry->locale, null, $entry->slug, $entry);

                DB::transaction(function () use ($entry, $type, $slug): void {
                    $entry->slug = $slug;
                    $entry->parent_id = null;
                    $this->paths->move($entry, $this->paths->pathFor($type, null, $slug), keepOldPaths: false);
                });

                return;
            } catch (UniqueConstraintViolationException) {
                $entry->forceFill($before);
            }
        }

        throw ProtectedActionException::because('content.entry.restore_conflict');
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

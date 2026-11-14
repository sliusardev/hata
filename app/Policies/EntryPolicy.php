<?php

namespace App\Policies;

use App\Models\Entry;
use App\Models\User;

/**
 * Each check is the type's own permission, `"{type}.{action}"`. Publishing, restoring and previewing need
 * `edit`; trashing and deleting for good need `delete`.
 */
class EntryPolicy
{
    public function viewAny(User $actor, string $type): bool
    {
        return $actor->can("{$type}.view");
    }

    public function view(User $actor, Entry $entry): bool
    {
        return $actor->can("{$entry->type}.view");
    }

    public function create(User $actor, string $type): bool
    {
        return $actor->can("{$type}.create");
    }

    public function update(User $actor, Entry $entry): bool
    {
        return $actor->can("{$entry->type}.edit");
    }

    public function delete(User $actor, Entry $entry): bool
    {
        return $actor->can("{$entry->type}.delete");
    }

    public function restore(User $actor, Entry $entry): bool
    {
        return $actor->can("{$entry->type}.edit");
    }

    public function forceDelete(User $actor, Entry $entry): bool
    {
        return $actor->can("{$entry->type}.delete");
    }

    /**
     * Opening a draft or scheduled record at its address.
     */
    public function preview(User $actor, Entry $entry): bool
    {
        return $actor->can("{$entry->type}.edit");
    }
}

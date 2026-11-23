<?php

namespace App\Policies;

use App\Models\Entry;
use App\Models\User;

/**
 * Each check is the type's own permission, `"{type}.{action}"`. Opening the list and a record also passes with
 * `edit` or `create` (as decision 31 does for roles), so neither is a dead end without `view`. Publishing,
 * restoring and previewing need `edit`; trashing and deleting for good need `delete`.
 */
class EntryPolicy
{
    public function viewAny(User $actor, string $type): bool
    {
        return $actor->canAny(["{$type}.view", "{$type}.edit", "{$type}.create"]);
    }

    public function view(User $actor, Entry $entry): bool
    {
        return $this->viewAny($actor, $entry->type);
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

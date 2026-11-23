<?php

namespace App\Actions\Content;

use App\Exceptions\ProtectedActionException;
use App\Models\Entry;
use App\Models\EntryGroup;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class LinkTranslations
{
    /**
     * Makes an existing record the translation of another in its language: the record leaves its own group (its
     * former translations stay linked to each other) and joins the other's, taking that group's shared values. A
     * group left empty goes with its file usages.
     *
     * @throws ProtectedActionException when the records are of different types, already linked, in the trash, or
     *                                  the group already has a record in that language (in the trash too)
     */
    public function handle(User $actor, Entry $entry, Entry $other): void
    {
        Gate::forUser($actor)->authorize('update', $entry);
        Gate::forUser($actor)->authorize('update', $other);

        if ($entry->type !== $other->type || $entry->entry_group_id === $other->entry_group_id || $entry->trashed() || $other->trashed()) {
            throw ProtectedActionException::because('content.link.invalid');
        }

        try {
            DB::transaction(fn () => $this->link($entry, $other));
        } catch (UniqueConstraintViolationException) {
            throw ProtectedActionException::because('content.link.overlap');
        }
    }

    /**
     * Locks the records of both groups in id order (so two links never wait on each other) and decides from what
     * the lock shows, not from the records the caller loaded.
     */
    private function link(Entry $entry, Entry $other): void
    {
        $rows = Entry::withTrashed()
            ->whereIn('entry_group_id', [$entry->entry_group_id, $other->entry_group_id])
            ->orderBy('id')
            ->lockForUpdate()
            ->get(['id', 'entry_group_id', 'locale', 'deleted_at']);

        $mine = $rows->firstWhere('id', $entry->id);
        $theirs = $rows->firstWhere('id', $other->id);

        // Either was linked, unlinked, moved to another language or trashed meanwhile.
        if ($mine === null || $theirs === null || $mine->trashed() || $theirs->trashed()
            || $mine->entry_group_id !== $entry->entry_group_id || $theirs->entry_group_id !== $other->entry_group_id) {
            throw ProtectedActionException::because('content.link.invalid');
        }

        if ($rows->where('entry_group_id', $mine->entry_group_id)->contains('locale', $theirs->locale)) {
            throw ProtectedActionException::because('content.link.overlap');
        }

        $left = $theirs->entry_group_id;

        // Not through the model: linking is not an edit, so `updated_at` stays.
        Entry::withTrashed()->whereKey($theirs->id)->toBase()->update(['entry_group_id' => $mine->entry_group_id]);

        // Only a group that is really empty goes (the foreign key would take a record added meanwhile with it).
        EntryGroup::deleteEmptied([$left]);
    }
}

<?php

namespace App\Actions\Content;

use App\Exceptions\ProtectedActionException;
use App\Models\Entry;
use App\Models\EntryGroup;
use App\Models\MediaUsage;
use App\Models\User;
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

        DB::transaction(function () use ($entry, $other): void {
            $locales = fn (int $group) => Entry::withTrashed()->where('entry_group_id', $group)->lockForUpdate()->pluck('locale');

            $mine = $locales($entry->entry_group_id);
            $theirs = $locales($other->entry_group_id);

            // Either was linked or unlinked meanwhile, or the language is taken.
            if (! $mine->contains($entry->locale) || ! $theirs->contains($other->locale) || $mine->contains($other->locale)) {
                throw ProtectedActionException::because('content.link.overlap');
            }

            $left = $other->entry_group_id;

            // Not through the model: linking is not an edit, so `updated_at` stays.
            Entry::withTrashed()->whereKey($other->id)->toBase()->update(['entry_group_id' => $entry->entry_group_id]);

            if ($theirs->count() === 1) {
                MediaUsage::query()->where('usable_type', (new EntryGroup)->getMorphClass())->where('usable_id', $left)->delete();
                EntryGroup::query()->whereKey($left)->delete();
            }
        });
    }
}

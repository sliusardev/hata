<?php

namespace App\Actions\Content;

use App\Enums\EntryStatus;
use App\Exceptions\ProtectedActionException;
use App\Models\Entry;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

class SetEntryStatus
{
    /**
     * Publishes a record or takes it off the site without opening its form. Publishing a record with no date, or
     * with a date still ahead, dates it now (it goes live at once); taking it off keeps the date for the next time.
     * Nothing else of the record changes.
     *
     * @throws ProtectedActionException when the record is in the trash
     */
    public function handle(User $actor, Entry $entry, EntryStatus $status): Entry
    {
        Gate::forUser($actor)->authorize('update', $entry);

        if ($entry->trashed()) {
            throw ProtectedActionException::because('content.entry.trashed');
        }

        $values = ['status' => $status];

        if ($status === EntryStatus::Published && ($entry->published_at === null || $entry->published_at->isFuture())) {
            $values['published_at'] = now();
        }

        // By key, not through the loaded model: a list row carries only some columns.
        Entry::query()->whereKey($entry->id)->update($values);

        return $entry->forceFill($values)->syncOriginal();
    }
}

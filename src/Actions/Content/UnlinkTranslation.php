<?php

namespace Hata\Actions\Content;

use App\Models\User;
use Hata\Exceptions\ProtectedActionException;
use Hata\Models\Entry;
use Hata\Models\EntryGroup;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class UnlinkTranslation
{
    public function __construct(private readonly EntryWriter $writer) {}

    /**
     * Takes a record out of its translations: it gets a group of its own with a copy of the shared fields' values
     * (and their file usages), and the others stay together.
     *
     * @throws ProtectedActionException when the record is in the trash or has no translations
     */
    public function handle(User $actor, Entry $entry): void
    {
        Gate::forUser($actor)->authorize('update', $entry);

        if ($entry->trashed()) {
            throw ProtectedActionException::because('hata::content.entry.trashed');
        }

        DB::transaction(function () use ($entry): void {
            $others = Entry::withTrashed()
                ->where('entry_group_id', $entry->entry_group_id)
                ->whereKeyNot($entry->id)
                ->lockForUpdate()
                ->exists();

            if (! $others) {
                throw ProtectedActionException::because('hata::content.link.alone');
            }

            // Read under a lock, not from the group the caller loaded: another translation may have just saved.
            $shared = EntryGroup::query()->whereKey($entry->entry_group_id)->lockForUpdate()->value('shared');

            $group = EntryGroup::query()->create(['type' => $entry->type, 'shared' => $shared]);

            Entry::withTrashed()->whereKey($entry->id)->toBase()->update(['entry_group_id' => $group->id]);

            $entry->entry_group_id = $group->id;
            $entry->syncOriginalAttribute('entry_group_id');
            $entry->setRelation('group', $group);

            $this->writer->syncSharedUsages($group, $entry->type());
        });
    }
}

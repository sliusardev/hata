<?php

namespace Hata\Actions\Content;

use Hata\Contracts\AdminUser;
use Hata\Enums\EntryStatus;
use Hata\Exceptions\ProtectedActionException;
use Hata\Models\Entry;
use Hata\Support\Content\ContentType;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * The list's actions on several picked records of one type. Ids that are not records of the type in the state the
 * action needs are left out, so a stale pick does nothing.
 */
class BulkEntries
{
    public function __construct(
        private readonly TrashEntry $trashEntry,
        private readonly RestoreEntry $restoreEntry,
        private readonly DeleteEntry $deleteEntry,
        private readonly SetEntryStatus $setStatus,
    ) {}

    /**
     * Publishes the live records or takes them off the site (SetEntryStatus authorizes each: the type's `edit`).
     *
     * @param  list<int>  $ids
     * @return int How many records were set.
     */
    public function setStatus(AdminUser $actor, ContentType $type, array $ids, EntryStatus $status): int
    {
        $entries = Entry::query()->ofType($type->key())->whereKey($ids)->get(['id', 'type', 'status', 'published_at', 'deleted_at']);

        foreach ($entries as $entry) {
            $this->setStatus->handle($actor, $entry, $status);
        }

        return $entries->count();
    }

    /**
     * Moves the live records to the trash, parents first: a page under a picked one is already there by its turn
     * and counts once. Needs the type's `delete` (TrashEntry authorizes each).
     *
     * @param  list<int>  $ids
     * @return int How many records went to the trash, those under the picked ones included.
     */
    public function trash(AdminUser $actor, ContentType $type, array $ids): int
    {
        $count = 0;

        $entries = Entry::query()
            ->ofType($type->key())
            ->whereKey($ids)
            ->orderBy('path')
            ->get(['id', 'type', 'locale', 'path', 'deleted_at']);

        foreach ($entries as $entry) {
            $count += $this->trashEntry->handle($actor, $entry);
        }

        return $count;
    }

    /**
     * Takes the trashed records out of the trash, parents first, so a page comes back under its parent; one a parent
     * already brought back is skipped. A record that cannot be restored does not stop the others. Needs the type's
     * `edit` (RestoreEntry authorizes each).
     *
     * @param  list<int>  $ids
     * @return array{restored: int, failed: list<string>} The count, and the message of every refusal.
     */
    public function restore(AdminUser $actor, ContentType $type, array $ids): array
    {
        $restored = 0;
        $failed = [];

        foreach (Entry::onlyTrashed()->ofType($type->key())->whereKey($ids)->orderBy('path')->pluck('id') as $id) {
            $entry = Entry::onlyTrashed()->ofType($type->key())->whereKey($id)->first();

            if ($entry === null) {
                continue;
            }

            try {
                $this->restoreEntry->handle($actor, $entry);
                $restored++;
            } catch (ProtectedActionException $exception) {
                $failed[] = $exception->getMessage();
            }
        }

        return ['restored' => $restored, 'failed' => array_values(array_unique($failed))];
    }

    /**
     * Deletes the trashed records for good with every trashed record under them, in one transaction
     * (DeleteEntry::purge()). Needs the type's `delete`.
     *
     * @param  list<int>  $ids
     * @return int How many records were deleted, those under the picked ones included.
     */
    public function delete(AdminUser $actor, ContentType $type, array $ids): int
    {
        Gate::forUser($actor)->authorize($type->permission('delete'));

        $entries = Entry::onlyTrashed()->ofType($type->key())->whereKey($ids)->get(['id', 'type', 'locale', 'path']);

        return DB::transaction(fn (): int => $this->deleteEntry->purge($entries));
    }
}

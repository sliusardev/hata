<?php

namespace Hata\Actions\Content;

use Hata\Contracts\AdminUser;
use Hata\Models\Entry;
use Hata\Support\Content\ContentType;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class EmptyEntryTrash
{
    private const int CHUNK = 200;

    public function __construct(private readonly DeleteEntry $delete) {}

    /**
     * Deletes for good every record of the type that is in the trash in the given languages, in chunks, each in its
     * own transaction (DeleteEntry::purge(), so usages, redirects and emptied groups go too). Needs the type's
     * `delete`.
     *
     * @param  list<string>  $locales
     * @return int How many records were removed.
     */
    public function handle(AdminUser $actor, ContentType $type, array $locales): int
    {
        Gate::forUser($actor)->authorize($type->permission('delete'));

        $removed = 0;

        Entry::onlyTrashed()
            ->ofType($type->key())
            ->whereIn('locale', $locales)
            ->select(['id', 'type', 'locale', 'path'])
            ->chunkById(self::CHUNK, function (Collection $entries) use (&$removed): void {
                $removed += DB::transaction(fn (): int => $this->delete->purge($entries));
            });

        return $removed;
    }
}

<?php

namespace App\Actions\Content;

use App\Exceptions\ProtectedActionException;
use App\Models\Entry;
use App\Models\User;
use App\Support\Content\EntryInput;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class UpdateEntry
{
    public function __construct(private readonly EntryWriter $writer) {}

    /**
     * Saves the form into the record. Its type and language never change; a changed slug or parent moves it and
     * its descendants (EntryPaths::move()) and keeps the old paths as redirects.
     *
     * @param  array<string, mixed>  $data  The form data; validated here (EntryInput).
     *
     * @throws ValidationException
     * @throws ProtectedActionException when the record is in the trash (it is restored first)
     */
    public function handle(User $actor, Entry $entry, array $data): Entry
    {
        Gate::forUser($actor)->authorize('update', $entry);

        if ($entry->trashed()) {
            throw ProtectedActionException::because('content.entry.trashed');
        }

        $type = $entry->type();
        $input = EntryInput::validate($type, $entry->locale, $entry, $data);

        try {
            return DB::transaction(function () use ($entry, $type, $input): Entry {
                $this->writer->save($entry, $type, $entry->group, $input);

                return $entry;
            });
        } catch (UniqueConstraintViolationException) {
            // Another request took the path between the check and the write.
            throw ValidationException::withMessages(['slug' => __('content.slug.taken')]);
        }
    }
}

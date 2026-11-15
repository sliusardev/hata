<?php

namespace App\Console\Commands;

use App\Models\Entry;
use App\Support\Content\ContentTypes;
use App\Support\Localization\ContentLocales;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use InvalidArgumentException;

/**
 * A type's base path (`blog` for posts) takes that first segment of the address for itself, so a record of a type
 * without a base that already sits at it, or under it, can no longer be reached: the router looks only in the type
 * that owns the base. New slugs are refused by the form; this finds the records stored before the base existed.
 */
#[Signature('content:check')]
#[Description('Check the base paths of the content types and list the records hidden by another type\'s base path')]
class CheckContentAddresses extends Command
{
    public function handle(ContentTypes $types, ContentLocales $locales): int
    {
        try {
            $types->assertValidBases();
        } catch (InvalidArgumentException $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        $rootTypes = [];
        $bases = [];

        foreach ($locales->codes() as $locale) {
            foreach ($types->all() as $type) {
                $base = trim((string) $type->base($locale), '/');

                if ($base === '') {
                    $rootTypes[$locale][] = $type->key();
                } else {
                    $bases[$locale][$base] = $type->key();
                }
            }
        }

        $rows = [];

        foreach ($bases as $locale => $owners) {
            foreach ($owners as $base => $owner) {
                $hidden = Entry::withTrashed()
                    ->whereIn('type', $rootTypes[$locale] ?? [])
                    ->where('locale', $locale)
                    ->where(fn ($query) => $query->where('path', $base)->orWhere('path', 'like', $base.'/%'))
                    ->orderBy('path')
                    ->get(['id', 'type', 'locale', 'path', 'deleted_at']);

                foreach ($hidden as $entry) {
                    $rows[] = [$entry->id, $entry->type, $locale, '/'.$entry->path.($entry->trashed() ? ' (in the trash)' : ''), $owner];
                }
            }
        }

        if ($rows === []) {
            $this->components->info('No record is hidden by the base path of another type.');

            return self::SUCCESS;
        }

        $this->components->error(count($rows).' record(s) cannot be reached: their address starts with the base path of another type.');
        $this->table(['Id', 'Type', 'Language', 'Address', 'Base of'], $rows);
        $this->components->bulletList(['Change the slug of the record (its old address is kept as a redirect only inside its own type), or choose another base for the type.']);

        return self::FAILURE;
    }
}

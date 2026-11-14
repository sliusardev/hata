<?php

namespace App\Actions\Content;

use App\Models\Entry;
use App\Models\EntryGroup;
use App\Models\User;
use App\Support\Content\ContentType;
use App\Support\Content\EntryInput;
use App\Support\Localization\ContentLocales;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

class CreateEntry
{
    public function __construct(private readonly EntryWriter $writer, private readonly ContentLocales $locales) {}

    /**
     * Creates a record in a language, as a translation of `$source` (joining its group) or in a new group. When
     * the group already has a record in that language, that record is returned unchanged, also when another
     * request created it a moment ago (the unique index decides the race).
     *
     * @param  array<string, mixed>  $data  The form data; validated here (EntryInput).
     *
     * @throws ValidationException
     */
    public function handle(User $actor, ContentType $type, string $locale, array $data, ?Entry $source = null): Entry
    {
        Gate::forUser($actor)->authorize('create', [Entry::class, $type->key()]);

        if ($source !== null && $source->type !== $type->key()) {
            throw new InvalidArgumentException("The source record is not of type [{$type->key()}].");
        }

        if (! $this->locales->has($locale)) {
            throw ValidationException::withMessages(['locale' => __('content.entry.locale_invalid')]);
        }

        if ($existing = $this->existing($source, $locale)) {
            return $existing;
        }

        try {
            $input = EntryInput::validate($type, $locale, null, $data);
            $input['author_id'] ??= $actor->id;

            if ($input['author_id'] !== $actor->id && ! $actor->can($type->permission('edit'))) {
                throw ValidationException::withMessages(['author_id' => __('content.entry.author_invalid')]);
            }

            return DB::transaction(function () use ($type, $locale, $source, $input): Entry {
                $group = $source !== null
                    ? $source->group
                    : EntryGroup::query()->create(['type' => $type->key(), 'shared' => null]);

                $entry = new Entry(['entry_group_id' => $group->id, 'type' => $type->key(), 'locale' => $locale]);
                $this->writer->save($entry, $type, $group, $input);

                return $entry;
            });
        } catch (ValidationException $exception) {
            // A request that created this translation meanwhile makes the slug look taken: open its record.
            return $this->existing($source, $locale) ?? throw $exception;
        } catch (UniqueConstraintViolationException) {
            return $this->existing($source, $locale)
                ?? throw ValidationException::withMessages(['slug' => __('content.slug.taken')]);
        }
    }

    /**
     * The record the source's group already has in the language, trashed or not (the unique index counts both).
     */
    private function existing(?Entry $source, string $locale): ?Entry
    {
        if ($source === null) {
            return null;
        }

        return Entry::withTrashed()
            ->where('entry_group_id', $source->entry_group_id)
            ->where('locale', $locale)
            ->first();
    }
}

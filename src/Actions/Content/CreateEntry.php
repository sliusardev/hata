<?php

namespace Hata\Actions\Content;

use Closure;
use Hata\Contracts\AdminUser;
use Hata\Enums\EntryStatus;
use Hata\Models\Entry;
use Hata\Models\EntryGroup;
use Hata\Support\Content\ContentType;
use Hata\Support\Content\EntryInput;
use Hata\Support\Content\EntryPaths;
use Hata\Support\Localization\ContentLocales;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

class CreateEntry
{
    public function __construct(
        private readonly EntryWriter $writer,
        private readonly ContentLocales $locales,
        private readonly EntryPaths $paths,
    ) {}

    /**
     * Creates a record in a language, as a translation of `$source` (joining its group) or in a new group. When
     * the group already has a record in that language, that record is returned unchanged, also when another
     * request created it a moment ago (the unique index decides the race). Publishing (or scheduling) needs the
     * type's `edit`, and so does choosing the author: without it the actor is the author, whatever the data says.
     * A translation (with `$source`) by an actor without `edit` leaves the group's shared values alone: they are
     * those of published records in other languages, so the shared fields of the data are ignored. A new record
     * (no source) has a group of its own, and its creator sets them.
     *
     * `$alsoSave` is called with the new record inside the same transaction, after it is written (a form's
     * registered cards save there), so whatever it throws rolls the record back. It is not called when the group's
     * existing record is returned instead.
     *
     * @param  array<string, mixed>  $data  The form data; validated here (EntryInput).
     * @param  (Closure(Entry): void)|null  $alsoSave
     *
     * @throws ValidationException
     */
    public function handle(AdminUser $actor, ContentType $type, string $locale, array $data, ?Entry $source = null, ?Closure $alsoSave = null): Entry
    {
        Gate::forUser($actor)->authorize('create', [Entry::class, $type->key()]);

        if ($source !== null && $source->type !== $type->key()) {
            throw new InvalidArgumentException("The source record is not of type [{$type->key()}].");
        }

        if (! $this->locales->has($locale)) {
            throw ValidationException::withMessages(['locale' => __('hata::content.entry.locale_invalid')]);
        }

        if ($existing = $this->existing($source, $locale)) {
            return $existing;
        }

        $mayEdit = $actor->can($type->permission('edit'));

        if (! $type->supports('publication')) {
            // A type without a status (a term) has no draft: its record is there or it is not.
            $data = [...$data, 'status' => EntryStatus::Published->value, 'published_at' => null];
        }

        if (! $mayEdit) {
            // Only those who may edit the type choose the author; anyone else writes as themselves.
            $data['author_id'] = null;
        }

        try {
            $input = EntryInput::validate($type, $locale, null, $data, withSharedFields: $source === null || $mayEdit);
            $input['author_id'] ??= $actor->id;

            // Making a term on the fly needs the right to create in that taxonomy.
            $input['new_terms'] = array_filter($input['new_terms'], fn (string $taxonomy): bool => $actor->can("{$taxonomy}.create"), ARRAY_FILTER_USE_KEY);

            if ($input['status'] === EntryStatus::Published && ! $mayEdit && $type->supports('publication')) {
                throw ValidationException::withMessages(['status' => __('hata::content.entry.publish_forbidden')]);
            }

            return DB::transaction(function () use ($type, $locale, $source, $input, $alsoSave): Entry {
                $this->paths->lockForSave(null, $input['parent'], $locale);

                $group = $source !== null
                    ? $source->group
                    : EntryGroup::query()->create(['type' => $type->key(), 'shared' => null]);

                $entry = new Entry(['entry_group_id' => $group->id, 'type' => $type->key(), 'locale' => $locale]);
                $this->writer->save($entry, $type, $group, $input);

                if ($alsoSave !== null) {
                    $alsoSave($entry);
                }

                return $entry;
            });
        } catch (ValidationException $exception) {
            // A request that created this translation meanwhile makes the slug look taken: open its record.
            return $this->existing($source, $locale) ?? throw $exception;
        } catch (UniqueConstraintViolationException) {
            return $this->existing($source, $locale)
                ?? throw ValidationException::withMessages(['slug' => __('hata::content.slug.taken')]);
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

        return EntryGroup::recordIn($source->entry_group_id, $locale);
    }
}

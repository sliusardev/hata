<?php

namespace Hata\Actions\Content;

use Hata\Contracts\AdminUser;
use Hata\Enums\EntryStatus;
use Hata\Exceptions\ProtectedActionException;
use Hata\Models\Entry;
use Hata\Models\EntryGroup;
use Hata\Support\Content\EntryInput;
use Hata\Support\Content\EntryPaths;
use Hata\Support\Localization\ContentLocales;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class UpdateEntry
{
    public function __construct(
        private readonly EntryWriter $writer,
        private readonly EntryPaths $paths,
        private readonly ContentLocales $locales,
    ) {}

    /**
     * Saves the form into the record. Its type never changes; a changed slug or parent moves it and its
     * descendants (EntryPaths::move()) and keeps the old paths as redirects.
     *
     * With `$locale` the record also becomes one of that language (EntryPaths::relocate(), the old address kept as
     * a redirect): only a language of the site that its group does not have yet (in the trash either), and only a
     * record with nothing under it, as its descendants would stay in the old language.
     *
     * @param  array<string, mixed>  $data  The form data; validated here (EntryInput).
     *
     * @throws ValidationException
     * @throws ProtectedActionException when the record is in the trash (it is restored first)
     */
    public function handle(AdminUser $actor, Entry $entry, array $data, ?string $locale = null): Entry
    {
        Gate::forUser($actor)->authorize('update', $entry);

        if ($entry->trashed()) {
            throw ProtectedActionException::because('hata::content.entry.trashed');
        }

        $type = $entry->type();
        $locale ??= $entry->locale;
        $moves = $locale !== $entry->locale;

        if ($moves) {
            $this->assertMayBecome($entry, $locale);
        }

        if (! $type->supports('publication')) {
            $data = [...$data, 'status' => EntryStatus::Published->value];
        }

        $input = EntryInput::validate($type, $locale, $entry, $data);
        $input['new_terms'] = array_filter($input['new_terms'], fn (string $taxonomy): bool => $actor->can("{$taxonomy}.create"), ARRAY_FILTER_USE_KEY);

        try {
            return DB::transaction(function () use ($entry, $type, $input, $locale, $moves): Entry {
                $this->paths->lockForSave($entry, $input['parent'], $locale);

                if ($moves) {
                    // Again under the lock: a page created under the record or a translation added meanwhile.
                    $this->assertMayBecome($entry, $locale);
                    $this->paths->relocate($entry, $locale, $this->paths->pathFor($type, $input['parent'], $input['slug']));
                }

                $this->writer->save($entry, $type, $entry->group, $input);

                return $entry;
            });
        } catch (UniqueConstraintViolationException) {
            // Another request took the language or the path between the check and the write.
            if ($moves && in_array($locale, EntryGroup::takenLocales($entry->entry_group_id), true)) {
                throw ValidationException::withMessages(['locale' => __('hata::content.entry.locale_taken')]);
            }

            throw ValidationException::withMessages(['slug' => __('hata::content.slug.taken')]);
        }
    }

    /**
     * @throws ValidationException
     */
    private function assertMayBecome(Entry $entry, string $locale): void
    {
        $problem = match (true) {
            ! $this->locales->has($locale) => 'hata::content.entry.locale_invalid',
            in_array($locale, EntryGroup::takenLocales($entry->entry_group_id), true) => 'hata::content.entry.locale_taken',
            $entry->descendants()->withTrashed()->exists() => 'hata::content.entry.locale_has_children',
            // Records are filed under terms of their own language: a term in use stays in its language.
            $entry->type()->isTaxonomy() && $entry->termRecords()->withTrashed()->exists() => 'hata::content.entry.locale_has_records',
            default => null,
        };

        if ($problem !== null) {
            throw ValidationException::withMessages(['locale' => __($problem)]);
        }
    }
}

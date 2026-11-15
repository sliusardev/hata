<?php

namespace App\Livewire\Admin;

use App\Actions\Content\RestoreEntry;
use App\Actions\Content\TrashEntry;
use App\Actions\Content\UnlinkTranslation;
use App\Actions\Content\UpdateEntry;
use App\Exceptions\ProtectedActionException;
use App\Models\Entry;
use App\Support\Localization\Language;
use App\Support\Toast;
use Flux\Flux;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;

/**
 * A saved record: edited with the type's `edit`, read-only with `view` alone or while it is in the trash (then
 * with Restore). Its language is changed with the form (applied on Save); its translations are linked and unlinked
 * at once, apart from the form.
 *
 * The page `pages::admin.content.edit` extends it and holds the markup (decision 32).
 *
 * @property-read Entry $entry
 * @property-read Collection<int, Entry> $groupRecords
 * @property-read bool $hasDescendants
 */
abstract class EntryEditPage extends EntryFormPage
{
    #[Locked]
    public int $entryId = 0;

    public bool $showTrashModal = false;

    #[Locked]
    public int $trashingChildren = 0;

    public bool $showUnlinkModal = false;

    /**
     * The record the unlink dialog asks about: this one or one of its translations.
     */
    #[Locked]
    public int $unlinkId = 0;

    /**
     * The record's language when the form opened. Save moves the record to another language only when the form's
     * language differs from it, so a form left open never undoes a change made elsewhere.
     */
    #[Locked]
    public string $openedLocale = '';

    /**
     * The record the route bound, used for the first render so it is not loaded twice; later requests load it again.
     */
    protected ?Entry $boundEntry = null;

    public function mount(string $type, Entry $entry): void
    {
        abort_unless($entry->type === $type, 404);

        $this->authorize('view', $entry);

        $this->type = $type;
        $this->locale = $entry->locale;
        $this->openedLocale = $entry->locale;
        $this->entryId = $entry->id;
        $this->boundEntry = $entry->loadMissing('group');
        $this->form->fillFromEntry($this->entry);
    }

    /**
     * The record with its group (shared field values), trashed or not.
     */
    #[Computed]
    public function entry(): Entry
    {
        return $this->boundEntry ?? Entry::withTrashed()->with('group')->ofType($this->type)->findOrFail($this->entryId);
    }

    public function record(): ?Entry
    {
        return $this->entry;
    }

    public function readOnly(): bool
    {
        return $this->entry->trashed() || ! $this->abilities['edit'];
    }

    /**
     * Every other record of the group, trashed ones included. One query.
     *
     * @return Collection<int, Entry>
     */
    #[Computed]
    public function groupRecords(): Collection
    {
        return Entry::withTrashed()
            ->where('entry_group_id', $this->entry->entry_group_id)
            ->whereKeyNot($this->entryId)
            ->get(['id', 'entry_group_id', 'type', 'locale', 'title', 'status', 'published_at', 'deleted_at']);
    }

    /**
     * The languages the record may become: its own, the form's, and those its group does not hold (in the trash
     * either). None while it is read-only or has records under it, which would stay in the old language.
     *
     * @return list<Language>
     */
    #[Computed]
    public function localeChoices(): array
    {
        if ($this->readOnly() || $this->hasDescendants) {
            return [];
        }

        $taken = $this->groupRecords->pluck('locale')->all();

        return array_values(array_filter(
            $this->languages,
            fn ($language): bool => in_array($language->code, [$this->entry->locale, $this->locale], true) || ! in_array($language->code, $taken, true),
        ));
    }

    /**
     * Whether anything sits under the record, in the trash too. One query, only on a multilingual tree.
     */
    #[Computed]
    public function hasDescendants(): bool
    {
        return $this->contentType->hierarchical()
            && count($this->languages) > 1
            && $this->entry->descendants()->withTrashed()->exists();
    }

    protected function authorizeLocaleChange(): void
    {
        $this->authorize('update', $this->entry);

        abort_if($this->entry->trashed(), 403);
    }

    protected function localeSource(): ?Entry
    {
        return $this->entry;
    }

    /**
     * The translation picker linked a record: show the group as it is now.
     */
    #[On('translations-changed')]
    public function translationsChanged(): void
    {
        unset($this->groupRecords, $this->localeChoices);
    }

    /**
     * Asks before a record leaves the translations: this one, or a live translation of it.
     */
    public function confirmUnlink(int $id): void
    {
        $this->authorizeLocaleChange();

        abort_if($this->unlinkTarget($id) === null, 404);

        $this->unlinkId = $id;
        $this->showUnlinkModal = true;
    }

    public function unlink(UnlinkTranslation $unlinkTranslation): void
    {
        $this->authorizeLocaleChange();

        $this->showUnlinkModal = false;

        $target = $this->unlinkTarget($this->unlinkId);

        try {
            abort_if($target === null, 404);
            $unlinkTranslation->handle(Auth::user(), $target);
        } catch (ProtectedActionException $exception) {
            Flux::toast(variant: 'danger', text: $exception->getMessage());

            return;
        }

        unset($this->groupRecords, $this->localeChoices);

        Flux::toast(variant: 'success', text: __('content.link.unlinked', ['title' => $target->title]));
    }

    /**
     * The record itself or a live record of its group, by id.
     */
    public function unlinkTarget(int $id): ?Entry
    {
        if ($id === $this->entryId) {
            return $this->entry;
        }

        $record = $this->groupRecords->firstWhere('id', $id);

        return $record === null || $record->trashed() ? null : $record->setRelation('group', $this->entry->group);
    }

    public function save(UpdateEntry $updateEntry): void
    {
        $this->authorize('update', $this->entry);

        // A record in the trash is read-only until it is restored.
        abort_if($this->entry->trashed(), 403);

        try {
            $entry = $updateEntry->handle(Auth::user(), $this->entry, $this->form->data($this->contentType), $this->locale !== $this->openedLocale ? $this->locale : null);
        } catch (ValidationException $exception) {
            $this->forgetUnsaved();
            $this->showErrors($exception);

            return;
        } catch (ProtectedActionException $exception) {
            $this->forgetUnsaved();
            Flux::toast(variant: 'danger', text: $exception->getMessage());

            return;
        }

        // No redirect: the page stays where it is (scroll, caret), re-rendered from the stored record.
        $this->forgetUnsaved();
        $this->openedLocale = $entry->locale;
        $this->locale = $entry->locale;
        $this->form->published_at = $this->form->localDate($this->entry->published_at);

        Flux::toast(variant: 'success', text: __('content.form.saved'));

        $this->dispatch(
            'entry-saved',
            locale: $this->entry->locale,
            state: strtolower($this->entry->state()->name),
            title: __('content.form.page_title', ['type' => $this->contentType->label(), 'title' => $this->entry->title]).' - '.config('app.name'),
        );
    }

    /**
     * A refused save leaves the action's unsaved values on the loaded record: render the stored one.
     */
    protected function forgetUnsaved(): void
    {
        $this->boundEntry = null;
        unset($this->entry, $this->groupRecords, $this->localeChoices);
    }

    public function confirmTrash(): void
    {
        $this->authorize('delete', $this->entry);

        $this->trashingChildren = $this->entry->descendantsCount();
        $this->showTrashModal = true;
    }

    public function trash(TrashEntry $trashEntry): void
    {
        $this->authorize('delete', $this->entry);

        // Already in the trash (another tab, another user): nothing to report.
        if ($this->entry->trashed()) {
            $this->showTrashModal = false;

            return;
        }

        $count = $trashEntry->handle(Auth::user(), $this->entry);

        $this->finished = true;

        Toast::success($count > 1
            ? trans_choice('content.list.trash.done_with_children', $count)
            : __('content.list.trash.done'));

        $this->redirectRoute('admin.content.index', ['type' => $this->type]);
    }

    public function restore(RestoreEntry $restoreEntry): void
    {
        $this->authorize('restore', $this->entry);

        try {
            $restored = $restoreEntry->handle(Auth::user(), $this->entry);
        } catch (ProtectedActionException $exception) {
            // The action cleared `deleted_at` on the loaded record before it gave up: render the stored one.
            $this->boundEntry = null;
            unset($this->entry);

            Flux::toast(variant: 'danger', text: $exception->getMessage());

            return;
        }

        Toast::success(__('content.list.restored', ['title' => $restored->title]));

        $this->redirectRoute('admin.content.edit', ['type' => $this->type, 'entry' => $this->entryId], navigate: true);
    }
}

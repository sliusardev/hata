<?php

use App\Actions\Content\RestoreEntry;
use App\Actions\Content\TrashEntry;
use App\Actions\Content\UpdateEntry;
use App\Exceptions\ProtectedActionException;
use App\Livewire\Admin\EntryFormPage;
use App\Models\Entry;
use App\Support\Toast;
use Flux\Flux;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;

/**
 * A saved record: edited with the type's `edit`, read-only with `view` alone or while it is in the trash (then
 * with Restore).
 */
new class extends EntryFormPage {
    #[Locked]
    public int $entryId = 0;

    public bool $showTrashModal = false;

    #[Locked]
    public int $trashingChildren = 0;

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
        $this->entryId = $entry->id;
        $this->boundEntry = $entry->loadMissing('group');
        $this->form->fillFromEntry($this->entry);
    }

    public function render(): mixed
    {
        return $this->view()->title(__('content.form.page_title', [
            'type' => $this->contentType->label(),
            'title' => $this->entry->title,
        ]));
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

    public function save(UpdateEntry $updateEntry): void
    {
        $this->authorize('update', $this->entry);

        // A record in the trash is read-only until it is restored.
        abort_if($this->entry->trashed(), 403);

        try {
            $updateEntry->handle(Auth::user(), $this->entry, $this->form->data($this->contentType));
        } catch (ValidationException $exception) {
            $this->showErrors($exception);

            return;
        } catch (ProtectedActionException $exception) {
            Flux::toast(variant: 'danger', text: $exception->getMessage());

            return;
        }

        $this->finished = true;

        Toast::success(__('content.form.saved'));

        $this->redirectRoute('admin.content.edit', ['type' => $this->type, 'entry' => $this->entryId]);
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

        $this->redirectRoute('admin.content.index', ['type' => $this->type, 'locale' => $this->locale]);
    }

    public function restore(RestoreEntry $restoreEntry): void
    {
        $this->authorize('restore', $this->entry);

        try {
            $restored = $restoreEntry->handle(Auth::user(), $this->entry);
        } catch (ProtectedActionException $exception) {
            Flux::toast(variant: 'danger', text: $exception->getMessage());

            return;
        }

        Toast::success(__('content.list.restored', ['title' => $restored->title]));

        $this->redirectRoute('admin.content.edit', ['type' => $this->type, 'entry' => $this->entryId]);
    }
}; ?>

@include('partials.admin.content.entry-form', [
    'entry' => $this->entry,
    'groupEntries' => $this->groupRecords->whereNull('deleted_at')->push($this->entry)->values(),
    'trashedEntries' => $this->groupRecords->whereNotNull('deleted_at')->keyBy('locale'),
    'fromId' => $this->entryId,
])

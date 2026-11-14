<?php

use App\Actions\Content\CreateEntry;
use App\Livewire\Admin\EntryFormPage;
use App\Models\Entry;
use App\Support\Content\EntryPaths;
use App\Support\Localization\ContentLocales;
use App\Support\Toast;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;

/**
 * A new record in the language of the query (`locale`), optionally a translation of another record (`from`), which
 * fills the form with its copy. Nothing is stored before Save.
 */
new class extends EntryFormPage {
    #[Locked]
    public ?int $sourceId = null;

    public function mount(string $type, ContentLocales $locales): void
    {
        $this->authorize('create', [Entry::class, $type]);

        $this->type = $type;

        $locale = request()->query('locale');

        if ($locale !== null) {
            abort_unless(is_string($locale) && $locales->has($locale), 404);
        } else {
            $remembered = (string) session('content.list_locale');
            $locale = $remembered !== '' && $locales->has($remembered) ? $remembered : $locales->defaultCode();
        }

        $this->locale = $locale;

        $from = request()->query('from');

        if ($from === null) {
            $this->form->fillEmpty($this->contentType, Auth::id());

            return;
        }

        abort_unless(is_string($from) && ctype_digit($from), 404);
        $source = Entry::query()->ofType($type)->findOrFail((int) $from);

        // The group already has this language (in the trash too): open that record instead of a form that could
        // never be saved.
        $existing = Entry::withTrashed()->where('entry_group_id', $source->entry_group_id)->where('locale', $locale)->value('id');

        if ($existing !== null) {
            $this->redirectRoute('admin.content.edit', ['type' => $type, 'entry' => $existing]);

            return;
        }

        $this->sourceId = $source->id;
        $this->form->fillFromData($source->copyFor($locale));

        $parent = $this->form->parent_id !== '' ? Entry::query()->find((int) $this->form->parent_id) : null;
        $this->form->slug = app(EntryPaths::class)->suggest($this->contentType, $locale, $parent, $this->form->title);
    }

    public function render(): mixed
    {
        return $this->view()->title($this->contentType->newLabel());
    }

    public function record(): ?Entry
    {
        return null;
    }

    public function readOnly(): bool
    {
        return false;
    }

    /**
     * The source's group (live records), for the language card of a translation; empty without a source.
     *
     * @return Collection<int, Entry>
     */
    #[Computed]
    public function groupEntries(): Collection
    {
        if ($this->sourceId === null) {
            return collect();
        }

        return Entry::query()
            ->where('entry_group_id', Entry::query()->select('entry_group_id')->whereKey($this->sourceId))
            ->get(['id', 'entry_group_id', 'type', 'locale', 'title', 'status', 'published_at']);
    }

    public function save(CreateEntry $createEntry): void
    {
        $this->authorize('create', [Entry::class, $this->type]);

        $source = $this->sourceId !== null ? Entry::query()->ofType($this->type)->find($this->sourceId) : null;

        try {
            $entry = $createEntry->handle(Auth::user(), $this->contentType, $this->locale, $this->form->data($this->contentType), $source);
        } catch (ValidationException $exception) {
            $this->showErrors($exception);

            return;
        }

        $this->finished = true;

        Toast::success($entry->wasRecentlyCreated ? __('content.form.created') : __('content.form.exists'));

        $this->redirectRoute('admin.content.edit', ['type' => $this->type, 'entry' => $entry->id]);
    }
}; ?>

@include('partials.admin.content.entry-form', [
    'entry' => null,
    'groupEntries' => $this->groupEntries,
    'trashedEntries' => collect(),
    'fromId' => $sourceId,
])

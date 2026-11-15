<?php

use App\Actions\Content\CreateEntry;
use App\Livewire\Admin\EntryFormPage;
use App\Models\Entry;
use App\Support\Content\EntryAuthors;
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
 * fills the form with its copy. Nothing is stored before Save. The language can still be changed in place
 * (`changeLocale()`), with no reload and nothing typed lost.
 */
new class extends EntryFormPage {
    #[Locked]
    public ?int $sourceId = null;


    public function mount(string $type, ContentLocales $locales): void
    {
        abort_unless(app(App\Support\Content\ContentTypes::class)->isActive($type), 404);

        $this->authorize('create', [Entry::class, $type]);

        $this->type = $type;

        $locale = request()->query('locale');

        if ($locale !== null) {
            abort_unless(is_string($locale) && $locales->has($locale), 404);
        } else {
            $remembered = (string) session(App\Livewire\Admin\EntryListPage::LOCALE_SESSION_KEY);
            $locale = $remembered !== '' && $locales->has($remembered) ? $remembered : $locales->defaultCode();
        }

        $this->locale = $locale;

        $from = request()->query('from');

        if ($from === null) {
            $this->form->fillEmpty($this->contentType, Auth::id());
            $this->publishByDefault();

            return;
        }

        abort_unless(is_string($from) && ctype_digit($from), 404);
        $source = Entry::query()->ofType($type)->findOrFail((int) $from);
        $this->authorize('view', $source);

        // The group already has this language (in the trash too): open that record instead of a form that could
        // never be saved.
        $existing = App\Models\EntryGroup::recordIn($source->entry_group_id, $locale);

        if ($existing !== null) {
            $this->redirectRoute('admin.content.edit', ['type' => $type, 'entry' => $existing->id]);

            return;
        }

        $this->sourceId = $source->id;
        $this->form->fillFromData($source->copyFor($locale));
        $this->publishByDefault();

        // The copied author may no longer be one (lost the permission, or deleted): the translation is the actor's.
        $authorId = (int) $this->form->author_id;

        if ($authorId === 0 || ! EntryAuthors::query($this->contentType)->whereKey($authorId)->exists()) {
            $this->form->author_id = (string) Auth::id();
        }

        $parent = $this->form->parent_id !== '' ? Entry::query()->find((int) $this->form->parent_id) : null;
        $this->form->slug = app(EntryPaths::class)->suggest($this->contentType, $locale, $parent, $this->form->title);
    }

    protected function authorizeLocaleChange(): void
    {
        $this->authorize('create', [Entry::class, $this->type]);
    }

    protected function localeSource(): ?Entry
    {
        return $this->sourceId !== null ? Entry::query()->ofType($this->type)->find($this->sourceId) : null;
    }

    /**
     * The languages the record can still be created in: every language of the site, or for a translation those
     * its group does not have yet (the form's own included).
     *
     * @return list<App\Support\Localization\Language>
     */
    #[Computed]
    public function localeChoices(): array
    {
        // The trash counts: the group's language is taken there too (CreateEntry would hand back that record).
        $groupId = $this->groupEntries->first()?->entry_group_id;
        $taken = $groupId === null ? [] : App\Models\EntryGroup::takenLocales($groupId);

        return array_values(array_filter(
            $this->languages,
            fn ($language): bool => $language->code === $this->locale || ! in_array($language->code, $taken, true),
        ));
    }

    /**
     * A new record starts as published (it goes live on Save, with the saving time as its date) for those who may
     * publish; with `create` alone it stays a draft, the only status they can save.
     */
    private function publishByDefault(): void
    {
        if ($this->abilities['edit'] || $this->contentType->isTaxonomy()) {
            $this->form->status = App\Enums\EntryStatus::Published->value;
        }
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

    public function sharedFieldsLocked(): bool
    {
        return $this->sourceId !== null && ! $this->abilities['edit'];
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

        // The source went to the trash or was deleted after the form opened: never save a standalone record instead.
        if ($this->sourceId !== null && $source === null) {
            $this->addError('form.source', __('content.form.source_gone'));
            $this->dispatch('entry-form-invalid');

            return;
        }

        try {
            $entry = $createEntry->handle(Auth::user(), $this->contentType, $this->locale, $this->form->data($this->contentType), $source);
        } catch (ValidationException $exception) {
            $this->showErrors($exception);

            return;
        }

        $this->finished = true;

        Toast::success($entry->wasRecentlyCreated ? __('content.form.created') : __('content.form.exists'));

        $this->redirectRoute('admin.content.edit', ['type' => $this->type, 'entry' => $entry->id], navigate: true);
    }
}; ?>

@include('partials.admin.content.entry-form', [
    'entry' => null,
    'groupEntries' => $this->groupEntries,
    'trashedEntries' => collect(),
    'fromId' => $sourceId,
    'localeChoices' => $this->localeChoices,
])

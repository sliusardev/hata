<?php

namespace App\Livewire\Admin;

use App\Livewire\Forms\EntryForm;
use App\Models\Entry;
use App\Models\Media;
use App\Support\Content\BodySanitizer;
use App\Support\Content\ContentType;
use App\Support\Content\ContentTypes;
use App\Support\Content\EntryAuthors;
use App\Support\Content\EntryPaths;
use App\Support\Content\Fields\RichText;
use App\Support\Localization\ContentLocales;
use App\Support\Localization\Language;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Renderless;
use Livewire\Component;

/**
 * Base for the record create and edit pages: the form, what the page offers around it (parents, authors, the
 * editor's images, the address prefix), the slug suggestion while the slug follows the title, and the hand-over of
 * the save actions' validation errors to the form's fields.
 *
 * @property-read ContentType $contentType
 * @property-read list<Language> $languages
 * @property-read Language $language
 * @property-read array{create: bool, edit: bool, delete: bool} $abilities
 * @property-read list<array{id: int, title: string, path: string, depth: int}> $parentOptions
 * @property-read array<int, string> $authorOptions
 * @property-read string|null $authorName
 * @property-read array<int, string> $editorImages
 * @property-read array<int, Media> $readOnlyMedia
 * @property-read string $addressPrefix
 * @property-read list<Language> $localeChoices
 */
abstract class EntryFormPage extends Component
{
    #[Locked]
    public string $type = '';

    /**
     * The form's language: a new record's comes from the query, a saved one's is the record's. Changed in place by
     * changeLocale(); a saved record takes the new one when it is saved.
     */
    #[Locked]
    public string $locale = '';

    public EntryForm $form;

    /**
     * Set when a save or a move to the trash succeeded, so the leave-page warning stays quiet on the redirect.
     */
    public bool $finished = false;

    /**
     * The saved record the form edits, or null for a new one.
     */
    abstract public function record(): ?Entry;

    /**
     * Whether every control is disabled (no Save, no trash).
     */
    abstract public function readOnly(): bool;

    /**
     * Whether the "For all languages" controls are disabled in a form that is otherwise editable: a translation made
     * by an actor without the type's `edit`, whose save leaves the group's shared values alone (CreateEntry).
     */
    public function sharedFieldsLocked(): bool
    {
        return false;
    }

    /**
     * The languages the form's language select offers (a computed property of the page); fewer than two and the
     * language is shown as fixed.
     *
     * @return list<Language>
     */
    abstract public function localeChoices(): array;

    /**
     * Checks that the actor may change the form's language; aborts otherwise.
     */
    abstract protected function authorizeLocaleChange(): void;

    /**
     * The record whose parent decides the parent in another language (its translation there, or none): the source
     * of a new translation, the record of a saved one, null for a plain new record.
     */
    abstract protected function localeSource(): ?Entry;

    /**
     * Makes the form one of another language, in place: the typed values stay, the parent becomes the one the
     * language has (none, or the translation of the source's parent), and the slug is made free in the language.
     * Nothing is stored. Returns what the browser keeps for itself (the address prefix and the parents' paths), or
     * null for a language that is not offered.
     *
     * @return array{locale: string, prefix: string, parentPaths: array<int, string>}|null
     */
    public function changeLocale(string $locale): ?array
    {
        $this->authorizeLocaleChange();

        if ($locale === $this->locale || ! in_array($locale, array_map(fn (Language $language): string => $language->code, $this->localeChoices), true)) {
            return null;
        }

        $this->locale = $locale;
        unset($this->language, $this->parentOptions, $this->addressPrefix, $this->localeChoices);

        $parentId = $this->localeSource()?->copyFor($locale)['parent_id'] ?? null;
        $parent = $parentId !== null ? Entry::query()->find((int) $parentId) : null;

        $this->form->parent_id = $parent !== null ? (string) $parent->id : '';

        $name = trim($this->form->slug) !== '' ? $this->form->slug : $this->form->title;

        if (trim($name) !== '') {
            $this->form->slug = app(EntryPaths::class)->suggest($this->contentType, $locale, $parent, $name, $this->record());
        }

        return [
            'locale' => $locale,
            'prefix' => $this->addressPrefix,
            'parentPaths' => collect($this->parentOptions)->mapWithKeys(fn (array $option): array => [(string) $option['id'] => $option['path']])->all(),
        ];
    }

    #[Computed]
    public function contentType(): ContentType
    {
        return app(ContentTypes::class)->get($this->type);
    }

    /**
     * Every language added to the site, the switched-off ones included.
     *
     * @return list<Language>
     */
    #[Computed]
    public function languages(): array
    {
        return app(ContentLocales::class)->all();
    }

    #[Computed]
    public function language(): Language
    {
        return app(ContentLocales::class)->language($this->locale);
    }

    /**
     * The type's permissions for the actor, checked once per request.
     *
     * @return array{create: bool, edit: bool, delete: bool}
     */
    #[Computed]
    public function abilities(): array
    {
        $user = Auth::user();

        return [
            'create' => $user->can($this->contentType->permission('create')),
            'edit' => $user->can($this->contentType->permission('edit')),
            'delete' => $user->can($this->contentType->permission('delete')),
        ];
    }

    /**
     * The records the form may choose as a parent, in tree order, without the record itself and its descendants.
     * One query; empty for a flat type; for a trashed (read-only) record only its actual parent.
     *
     * @return list<array{id: int, title: string, path: string, depth: int}>
     */
    #[Computed]
    public function parentOptions(): array
    {
        if (! $this->contentType->hierarchical()) {
            return [];
        }

        $record = $this->record();

        // A trashed record is read-only: it shows its actual parent, which may be in the trash too.
        if ($record?->trashed()) {
            $parent = $record->parent_id !== null ? Entry::withTrashed()->find($record->parent_id, ['id', 'title', 'path']) : null;

            return $parent === null ? [] : [['id' => $parent->id, 'title' => $parent->title, 'path' => $parent->path, 'depth' => 0]];
        }

        return array_values(Entry::query()
            ->ofType($this->type)
            ->inLocale($this->locale)
            // The record's own path means something only in its own language (a form changing it has no descendants).
            ->when($record !== null && $record->locale === $this->locale, fn ($query) => $query
                ->whereKeyNot($record->id)
                ->where('path', 'not like', $record->path.'/%'))
            ->orderByRaw("replace(path, '/', ' ') asc")
            ->orderBy('id')
            ->get(['id', 'title', 'path'])
            ->map(fn (Entry $entry): array => [
                'id' => $entry->id,
                'title' => $entry->title,
                'path' => $entry->path,
                'depth' => $entry->depth(),
            ])
            ->all());
    }

    /**
     * The users the author select offers (id => name), with the record's current author kept even when they can
     * no longer be chosen. Only read for an actor with the type's `edit`.
     *
     * @return array<int, string>
     */
    #[Computed]
    public function authorOptions(): array
    {
        $options = EntryAuthors::options($this->contentType);
        $current = (int) $this->form->author_id;

        if ($current > 0 && ! isset($options[$current]) && ($name = $this->record()?->author?->name) !== null) {
            $options[$current] = $name;
        }

        return $options;
    }

    /**
     * The author's name for a form whose author cannot be changed by the actor.
     */
    #[Computed]
    public function authorName(): ?string
    {
        $record = $this->record();

        return $record !== null ? $record->author?->name : Auth::user()->name;
    }

    /**
     * Current URLs of the images in the body and in the rich text fields (id => url), so the editors can show
     * them. One query when there is any image, none otherwise.
     *
     * @return array<int, string>
     */
    #[Computed]
    public function editorImages(): array
    {
        $sanitizer = app(BodySanitizer::class);
        $ids = $sanitizer->mediaIds($this->form->body);

        foreach ($this->contentType->fields() as $field) {
            if ($field instanceof RichText && is_string($this->form->fields[$field->name] ?? null)) {
                $ids = [...$ids, ...$sanitizer->mediaIds($this->form->fields[$field->name])];
            }
        }

        if ($ids === []) {
            return [];
        }

        return Media::query()->whereIn('id', array_unique($ids))->get()
            ->filter(fn (Media $media): bool => $media->isImage())
            ->mapWithKeys(fn (Media $media): array => [$media->id => $media->url()])
            ->all();
    }

    /**
     * The files a read-only form shows instead of pickers (the image and the file fields), by id. One query.
     *
     * @return array<int, Media>
     */
    #[Computed]
    public function readOnlyMedia(): array
    {
        $ids = $this->form->image_id !== null ? [$this->form->image_id] : [];

        foreach ($this->contentType->fields() as $field) {
            if ($field->holdsMedia()) {
                $ids = [...$ids, ...$field->mediaIds($this->form->fields[$field->name] ?? null)];
            }
        }

        if ($ids === []) {
            return [];
        }

        return Media::query()->whereIn('id', array_unique($ids))->get()->keyBy('id')->all();
    }

    /**
     * The part of the address in front of the parent's path: the site address without the scheme, the language
     * prefix (none for the default content language) and the type's base, ending with a slash.
     */
    #[Computed]
    public function addressPrefix(): string
    {
        $segments = [preg_replace('#^https?://#', '', rtrim(url('/'), '/'))];

        if ($this->locale !== app(ContentLocales::class)->defaultCode()) {
            $segments[] = $this->locale;
        }

        $base = $this->contentType->base($this->locale);

        if ($base !== null && trim($base, '/') !== '') {
            $segments[] = trim($base, '/');
        }

        return implode('/', $segments).'/';
    }

    /**
     * The whole prefix in front of the slug: addressPrefix and the chosen parent's path. The browser keeps it up to
     * date as the parent changes; this is what the page shows before Alpine starts.
     */
    public function fullPrefix(): string
    {
        foreach ($this->parentOptions as $option) {
            if ((string) $option['id'] === $this->form->parent_id) {
                return $this->addressPrefix.$option['path'].'/';
            }
        }

        return $this->addressPrefix;
    }

    /**
     * A free slug for the form's title under the form's parent, in the record's language. Called from the browser
     * while the slug still follows the title; renders nothing and changes no property.
     */
    #[Renderless]
    public function suggestSlug(): string
    {
        $parent = null;

        if ($this->contentType->hierarchical() && ctype_digit($this->form->parent_id)) {
            $parent = Entry::query()->ofType($this->type)->inLocale($this->locale)->find((int) $this->form->parent_id);
        }

        // Only returned: the browser applies it while the slug still follows the title. Setting the property here
        // would patch it into the page with the response and overwrite a slug typed meanwhile.
        return app(EntryPaths::class)->suggest($this->contentType, $this->locale, $parent, $this->form->title, $this->record());
    }

    /**
     * Puts a save action's validation errors on the form's fields and tells the browser to scroll to the first.
     */
    protected function showErrors(ValidationException $exception): void
    {
        foreach ($exception->errors() as $key => $messages) {
            foreach ($messages as $message) {
                $this->addError("form.{$key}", $message);
            }
        }

        $this->dispatch('entry-form-invalid');
    }
}

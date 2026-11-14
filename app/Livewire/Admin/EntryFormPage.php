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
 */
abstract class EntryFormPage extends Component
{
    #[Locked]
    public string $type = '';

    /**
     * The record's language: a new record's comes from the query, a saved one's never changes.
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
     * One query; empty for a flat type.
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

        return array_values(Entry::query()
            ->ofType($this->type)
            ->inLocale($this->locale)
            ->when($record !== null, fn ($query) => $query
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
     * A free slug for the form's title under the form's parent, in the record's language. Called from the browser
     * while the slug still follows the title; renders nothing.
     */
    #[Renderless]
    public function suggestSlug(): string
    {
        $parent = null;

        if ($this->contentType->hierarchical() && ctype_digit($this->form->parent_id)) {
            $parent = Entry::query()->ofType($this->type)->inLocale($this->locale)->find((int) $this->form->parent_id);
        }

        $slug = app(EntryPaths::class)->suggest($this->contentType, $this->locale, $parent, $this->form->title, $this->record());
        $this->form->slug = $slug;

        return $slug;
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

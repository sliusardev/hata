<?php

namespace Hata\Livewire\Admin;

use Hata\Livewire\Forms\EntryForm;
use Hata\Models\Entry;
use Hata\Models\Media;
use Hata\Support\Content\BodySanitizer;
use Hata\Support\Content\ContentType;
use Hata\Support\Content\ContentTypes;
use Hata\Support\Content\EntryAuthors;
use Hata\Support\Content\EntryPaths;
use Hata\Support\Content\Fields\RichText;
use Hata\Support\Content\HomePage;
use Hata\Support\Content\Types\PagesType;
use Hata\Support\Localization\ContentLocales;
use Hata\Support\Localization\Language;
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
 * @property-read array<string, ContentType> $taxonomies
 * @property-read array<string, list<array{id: int, title: string, depth: int}>> $termOptions
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
     * How many terms of one taxonomy a terms card offers.
     */
    public const int TERM_OPTIONS = 500;

    /**
     * The terms chosen in each language the form has been in, so a language change and back keeps them.
     *
     * @var array<string, list<string>>
     */
    #[Locked]
    public array $termsByLocale = [];

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
     * Nothing is stored. Returns what the browser keeps for itself (the address prefix; the parents' paths come
     * with the re-rendered options), or null for a language that is not offered.
     *
     * @return array{locale: string, prefix: string}|null
     */
    public function changeLocale(string $locale): ?array
    {
        $this->authorizeLocaleChange();

        if ($locale === $this->locale || ! in_array($locale, array_map(fn (Language $language): string => $language->code, $this->localeChoices), true)) {
            return null;
        }

        // Terms are of one language: the record takes those that have a translation in the new one. What was
        // chosen in a language is remembered, so switching away and back loses nothing.
        $this->termsByLocale[$this->locale] = $this->form->terms;
        $this->form->terms = $this->termsByLocale[$locale]
            ?? array_map(strval(...), Entry::translationIds($this->form->terms, $locale));

        $this->locale = $locale;
        unset($this->language, $this->parentOptions, $this->addressPrefix, $this->localeChoices, $this->termOptions);

        $parent = $this->localeSource()?->parentTranslation($locale);

        $this->form->parent_id = $parent !== null ? (string) $parent->id : '';

        $name = trim($this->form->slug) !== '' ? $this->form->slug : $this->form->title;

        if (trim($name) !== '') {
            $this->form->slug = app(EntryPaths::class)->suggest($this->contentType, $locale, $parent, $name, $this->record());
        }

        return [
            'locale' => $locale,
            'prefix' => $this->addressPrefix,
        ];
    }

    /**
     * The taxonomies of the record's type that are on, by key.
     *
     * @return array<string, ContentType>
     */
    #[Computed]
    public function taxonomies(): array
    {
        $types = app(ContentTypes::class);

        return array_filter($types->taxonomiesOf($this->type), fn (ContentType $taxonomy): bool => $types->isActive($taxonomy->key()));
    }

    /**
     * The live terms of each taxonomy in the form's language, in tree order: what the terms cards offer. One
     * query per taxonomy (each has its own limit, `TERM_OPTIONS`), none for a type without any.
     *
     * @return array<string, list<array{id: int, title: string, depth: int}>>
     */
    #[Computed]
    public function termOptions(): array
    {
        $options = [];
        $chosen = array_map(intval(...), $this->form->terms);

        foreach ($this->taxonomies as $key => $taxonomy) {
            $terms = Entry::query()
                ->ofType($key)
                ->where('locale', $this->locale)
                ->orderByRaw("replace(path, '/', ' ') asc")
                ->limit(self::TERM_OPTIONS)
                ->get(['id', 'type', 'title', 'path']);

            // A chosen term beyond the limit is still offered, so it can be taken off.
            if ($terms->count() === self::TERM_OPTIONS && array_diff($chosen, $terms->modelKeys()) !== []) {
                $terms = $terms->concat(Entry::query()->ofType($key)->whereKey(array_diff($chosen, $terms->modelKeys()))->get(['id', 'type', 'title', 'path']));
            }

            $options[$key] = array_values($terms->map(fn (Entry $term): array => ['id' => $term->id, 'title' => $term->title, 'depth' => $term->depth()])->all());
        }

        return $options;
    }

    /**
     * The group of the chosen home page when the form is of the pages type, else null: at most one query per
     * render, for the header's badge.
     */
    #[Computed]
    public function homeGroupId(): ?int
    {
        return $this->type === PagesType::KEY ? app(HomePage::class)->groupId() : null;
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

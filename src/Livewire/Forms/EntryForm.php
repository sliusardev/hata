<?php

namespace Hata\Livewire\Forms;

use DateTimeInterface;
use Hata\Enums\EntryStatus;
use Hata\Models\Entry;
use Hata\Support\Content\ContentType;
use Hata\Support\Content\ContentTypes;
use Illuminate\Support\Facades\Date;
use Livewire\Form;

/**
 * The record form's values as the browser binds them. Validation and conversion happen in the save actions
 * (EntryInput), so this class only fills the form and hands its data over.
 */
class EntryForm extends Form
{
    public string $title = '';

    public string $slug = '';

    /**
     * A record id as the native select sends it ('' for no parent).
     */
    public string $parent_id = '';

    public string $author_id = '';

    public ?int $image_id = null;

    public string $status = 'draft';

    /**
     * `Y-m-d\TH:i` in the site's display timezone (decision 38), as `<input type="datetime-local">` wants it.
     */
    public string $published_at = '';

    public string $excerpt = '';

    public string $seo_title = '';

    public string $seo_description = '';

    public string $body = '';

    /**
     * The type's own fields by name, as each field's `toForm()` gives them.
     *
     * @var array<string, mixed>
     */
    public array $fields = [];

    /**
     * The ids of the terms the record is filed under (strings, as the checkboxes send them).
     *
     * @var list<string>
     */
    public array $terms = [];

    /**
     * Names of terms to make with the record, by taxonomy key (tags typed in the form).
     *
     * @var array<string, list<string>>
     */
    public array $newTerms = [];

    /**
     * A stored date as the `datetime-local` input takes it, in the site's display timezone; empty for none.
     */
    public function localDate(?DateTimeInterface $date): string
    {
        return $date === null ? '' : Date::instance($date)->tz((string) config('admin.timezone'))->format('Y-m-d\TH:i');
    }

    public function fillFromEntry(Entry $entry): void
    {
        $this->fillFromData([
            'title' => $entry->title,
            'slug' => $entry->slug,
            'parent_id' => $entry->parent_id,
            'author_id' => $entry->author_id,
            'image_id' => $entry->image_id,
            'status' => $entry->status->value,
            'published_at' => $entry->published_at,
            'excerpt' => $entry->excerpt,
            'terms' => app(ContentTypes::class)->taxonomiesOf($entry->type) === [] ? [] : $entry->terms()->pluck('entries.id')->all(),
            'seo_title' => $entry->seo_title,
            'seo_description' => $entry->seo_description,
            'body' => $entry->body,
            'fields' => collect($entry->type()->fields())
                ->mapWithKeys(fn ($field): array => [$field->name => $field->toForm($entry->fieldValue($field->name))])
                ->all(),
        ]);
    }

    /**
     * Fills the form from data shaped like `Entry::copyFor()` (ids as ints or null, the date as a Carbon or null).
     *
     * @param  array<string, mixed>  $data
     */
    public function fillFromData(array $data): void
    {
        $date = $data['published_at'] ?? null;

        $this->title = (string) ($data['title'] ?? '');
        $this->slug = (string) ($data['slug'] ?? '');
        $this->parent_id = isset($data['parent_id']) ? (string) $data['parent_id'] : '';
        $this->author_id = isset($data['author_id']) ? (string) $data['author_id'] : '';
        $this->image_id = isset($data['image_id']) ? (int) $data['image_id'] : null;
        $this->status = (string) ($data['status'] ?? EntryStatus::Draft->value);
        $this->published_at = $this->localDate($date instanceof DateTimeInterface ? $date : null);
        $this->excerpt = (string) ($data['excerpt'] ?? '');
        $this->seo_title = (string) ($data['seo_title'] ?? '');
        $this->seo_description = (string) ($data['seo_description'] ?? '');
        $this->body = (string) ($data['body'] ?? '');
        $this->fields = is_array($data['fields'] ?? null) ? $data['fields'] : [];
        $this->terms = array_values(array_map(strval(...), is_array($data['terms'] ?? null) ? $data['terms'] : []));
        $this->newTerms = [];
    }

    /**
     * Empty values for a new record of a type: every field present in its empty form value.
     */
    public function fillEmpty(ContentType $type, ?int $authorId): void
    {
        $this->fillFromData([
            'author_id' => $authorId,
            'fields' => collect($type->fields())->mapWithKeys(fn ($field): array => [$field->name => $field->toForm(null)])->all(),
        ]);
    }

    /**
     * The data the save actions take (EntryInput validates and converts it).
     *
     * @return array<string, mixed>
     */
    public function data(ContentType $type): array
    {
        $data = [
            'title' => $this->title,
            'slug' => trim($this->slug),
            'author_id' => $this->author_id !== '' ? $this->author_id : null,
            'image_id' => $this->image_id,
            'status' => $this->status,
            'published_at' => $this->published_at !== '' ? $this->published_at : null,
            'excerpt' => $this->excerpt,
            'seo_title' => $this->seo_title,
            'seo_description' => $this->seo_description,
            'body' => $this->body,
            'fields' => $this->fields,
            'terms' => $this->terms,
            'new_terms' => $this->newTerms,
        ];

        if ($type->hierarchical()) {
            $data['parent_id'] = $this->parent_id !== '' ? $this->parent_id : null;
        }

        return $data;
    }
}

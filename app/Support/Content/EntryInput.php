<?php

namespace App\Support\Content;

use App\Enums\EntryStatus;
use App\Models\Entry;
use App\Rules\EntrySlug;
use App\Rules\MediaSelection;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Contracts\Validation\DataAwareRule;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * The validation of a record's form data, common fields and the type's own, and its conversion into the values
 * the save actions write. The actions validate themselves, so a tampered request is a field error whoever sends it.
 *
 * @phpstan-type EntryData array{
 *     title: string,
 *     slug: string,
 *     parent: Entry|null,
 *     author_id: int|null,
 *     image_id: int|null,
 *     status: EntryStatus,
 *     published_at: CarbonImmutable|null,
 *     excerpt: string|null,
 *     seo_title: string|null,
 *     seo_description: string|null,
 *     body: string|null,
 *     fields: array<string, mixed>,
 * }
 */
final class EntryInput
{
    /**
     * The most a body may weigh as sent, well under what the sanitizer cuts at (BodySanitizer::MAX_LENGTH), so
     * a body that is too long is a field error and never silently cut.
     */
    public const int BODY_MAX_BYTES = 1_000_000;

    /**
     * @return array<string, list<mixed>>
     */
    public static function rules(ContentType $type, string $locale, ?Entry $entry): array
    {
        return self::buildRules($type, $locale, $entry, self::parentResolver($type, $locale, $entry));
    }

    /**
     * Validates the data and returns it ready to write: ids as integers, the parent as a record, the status as
     * the enum, the date in UTC (it arrives in the site's display timezone, decision 38), empty texts as null and
     * every field of the type keyed by name (its form value; the writer converts it for storage).
     *
     * @param  array<string, mixed>  $data
     * @return EntryData
     *
     * @throws ValidationException
     */
    public static function validate(ContentType $type, string $locale, ?Entry $entry, array $data): array
    {
        $parent = self::parentResolver($type, $locale, $entry);
        $validated = Validator::make($data, self::buildRules($type, $locale, $entry, $parent))->validate();

        $fields = [];

        foreach ($type->fields() as $field) {
            $fields[$field->name] = $validated['fields'][$field->name] ?? null;
        }

        return [
            'title' => trim((string) $validated['title']),
            'slug' => (string) $validated['slug'],
            'parent' => $type->hierarchical() ? $parent($validated['parent_id'] ?? null) : null,
            'author_id' => self::id($validated['author_id'] ?? null),
            'image_id' => self::id($validated['image_id'] ?? null),
            'status' => EntryStatus::from((string) $validated['status']),
            'published_at' => self::publishedAt($validated['published_at'] ?? null),
            'excerpt' => self::text($validated['excerpt'] ?? null),
            'seo_title' => self::text($validated['seo_title'] ?? null),
            'seo_description' => self::text($validated['seo_description'] ?? null),
            'body' => is_string($validated['body'] ?? null) ? $validated['body'] : null,
            'fields' => $fields,
        ];
    }

    /**
     * The date of a form, read in the site's display timezone, as UTC.
     */
    public static function publishedAt(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        return Date::parse($value, (string) config('admin.timezone'))->utc()->toImmutable();
    }

    /**
     * @param  Closure(mixed): ?Entry  $parent
     * @return array<string, list<mixed>>
     */
    private static function buildRules(ContentType $type, string $locale, ?Entry $entry, Closure $parent): array
    {
        $rules = [
            'title' => ['required', 'string', 'max:255'],
            'slug' => ['required', 'string', self::slugRule($type, $locale, $entry, $parent)],
            'parent_id' => $type->hierarchical()
                ? ['nullable', 'integer', function (string $attribute, mixed $value, Closure $fail) use ($parent): void {
                    if ($parent($value) === null) {
                        $fail('content.entry.parent_invalid')->translate();
                    }
                }]
                : ['prohibited'],
            'author_id' => ['nullable', 'integer', function (string $attribute, mixed $value, Closure $fail) use ($type, $entry): void {
                $id = (int) $value;
                $isCurrent = $entry?->author_id !== null && $entry->author_id === $id;

                if (! $isCurrent && ! EntryAuthors::query($type)->whereKey($id)->exists()) {
                    $fail('content.entry.author_invalid')->translate();
                }
            }],
            'image_id' => ['nullable', new MediaSelection('image')],
            'status' => ['required', Rule::enum(EntryStatus::class)],
            'published_at' => ['nullable', 'date'],
            'excerpt' => ['nullable', 'string', 'max:255'],
            'seo_title' => ['nullable', 'string', 'max:255'],
            'seo_description' => ['nullable', 'string', 'max:500'],
            'body' => ['nullable', 'string', function (string $attribute, mixed $value, Closure $fail): void {
                if (is_string($value) && strlen($value) > self::BODY_MAX_BYTES) {
                    $fail('content.entry.body_too_long')->translate();
                }
            }],
            'fields' => ['nullable', 'array'],
        ];

        foreach ($type->fields() as $field) {
            $rules["fields.{$field->name}"] = $field->rules();
        }

        return $rules;
    }

    /**
     * A parent the record may have: a record of the same type and language, not trashed, and neither the record
     * itself nor one of its descendants. Null for anything else. Remembers each answer, so the parent rule, the
     * slug rule and the caller share one query.
     *
     * @return Closure(mixed): ?Entry
     */
    private static function parentResolver(ContentType $type, string $locale, ?Entry $entry): Closure
    {
        $found = [];

        return function (mixed $id) use (&$found, $type, $locale, $entry): ?Entry {
            if (! $type->hierarchical() || ! (is_int($id) || (is_string($id) && ctype_digit($id))) || (int) $id < 1) {
                return null;
            }

            $id = (int) $id;

            if (! array_key_exists($id, $found)) {
                $parent = Entry::query()->where('type', $type->key())->where('locale', $locale)->find($id);
                $ownPath = $entry?->exists ? (string) $entry->getOriginal('path') : null;

                if ($parent !== null && $ownPath !== null && ($parent->is($entry) || str_starts_with($parent->path, $ownPath.'/'))) {
                    $parent = null;
                }

                $found[$id] = $parent;
            }

            return $found[$id];
        };
    }

    /**
     * `EntrySlug` under the parent the data names. While that parent is not a valid one, the slug is not checked:
     * the parent has its own error, and the path it would give means nothing.
     *
     * @param  Closure(mixed): ?Entry  $parent
     */
    private static function slugRule(ContentType $type, string $locale, ?Entry $entry, Closure $parent): ValidationRule
    {
        return new class($type, $locale, $entry, $parent) implements DataAwareRule, ValidationRule
        {
            /**
             * @var array<array-key, mixed>
             */
            private array $data = [];

            /**
             * @param  Closure(mixed): ?Entry  $parent
             */
            public function __construct(
                private readonly ContentType $type,
                private readonly string $locale,
                private readonly ?Entry $entry,
                private readonly Closure $parent,
            ) {}

            /**
             * @param  array<array-key, mixed>  $data
             */
            public function setData(array $data): static
            {
                $this->data = $data;

                return $this;
            }

            public function validate(string $attribute, mixed $value, Closure $fail): void
            {
                $parentId = $this->type->hierarchical() ? ($this->data['parent_id'] ?? null) : null;
                $parent = blank($parentId) ? null : ($this->parent)($parentId);

                if (! blank($parentId) && $parent === null) {
                    return;
                }

                (new EntrySlug($this->type, $this->locale, $parent, $this->entry))->validate($attribute, $value, $fail);
            }
        };
    }

    private static function id(mixed $value): ?int
    {
        return is_numeric($value) && (int) $value > 0 ? (int) $value : null;
    }

    private static function text(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}

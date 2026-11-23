<?php

namespace Hata\Support\Content;

use Carbon\CarbonImmutable;
use Closure;
use Hata\Enums\EntryStatus;
use Hata\Models\Entry;
use Hata\Rules\EntrySlug;
use Hata\Rules\MediaSelection;
use Illuminate\Contracts\Validation\DataAwareRule;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
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
 *     terms: list<int>,
 *     new_terms: array<string, list<string>>,
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
     * Validates the data and returns it ready to write: ids as integers, the parent as a record, the status as
     * the enum, the date in UTC (it arrives in the site's display timezone, decision 38), empty texts as null and
     * every field of the type keyed by name (its form value; the writer converts it for storage).
     *
     * Without `$withSharedFields` the type's shared fields are neither validated nor returned: the caller ignores
     * them (a translation created by an actor who may not edit the type leaves the group's values alone).
     *
     * @param  array<string, mixed>  $data
     * @return EntryData
     *
     * @throws ValidationException
     */
    public static function validate(ContentType $type, string $locale, ?Entry $entry, array $data, bool $withSharedFields = true): array
    {
        $parent = self::parentResolver($type, $locale, $entry);
        $validated = Validator::make($data, self::buildRules($type, $locale, $entry, $parent, $withSharedFields))->validate();

        $fields = [];

        foreach ($withSharedFields ? $type->fields() : $type->localFields() as $field) {
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
            'terms' => array_values(array_unique(array_map(intval(...), (array) ($validated['terms'] ?? [])))),
            'new_terms' => self::newTerms($type, $validated['new_terms'] ?? []),
        ];
    }

    /**
     * The names of terms to create with the record, by taxonomy: only for the flat taxonomies of the type (tags),
     * trimmed, without empty ones and repeats.
     *
     * @return array<string, list<string>>
     */
    private static function newTerms(ContentType $type, mixed $given): array
    {
        $names = [];

        foreach (app(ContentTypes::class)->taxonomiesOf($type->key()) as $key => $taxonomy) {
            if ($taxonomy->hierarchical() || ! is_array($given) || ! is_array($given[$key] ?? null)) {
                continue;
            }

            $own = array_values(array_unique(array_filter(array_map(fn (mixed $name): string => trim((string) $name), $given[$key]), fn (string $name): bool => $name !== '')));

            if ($own !== []) {
                $names[$key] = $own;
            }
        }

        return $names;
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
    private static function buildRules(ContentType $type, string $locale, ?Entry $entry, Closure $parent, bool $withSharedFields = true): array
    {
        $rules = [
            'title' => ['required', 'string', 'max:255'],
            'slug' => ['required', 'string', self::slugRule($type, $locale, $entry, $parent)],
            'parent_id' => $type->hierarchical()
                ? ['nullable', 'integer', self::dataRule(function (mixed $value, array $data, Closure $fail) use ($type, $entry, $parent): void {
                    $newParent = $parent($value);

                    if ($newParent === null) {
                        $fail('hata::content.entry.parent_invalid')->translate();

                        return;
                    }

                    // Only the parent changed: a slug change reports the same problem on the slug.
                    $slug = $data['slug'] ?? null;

                    if ($entry?->exists && is_string($slug) && $slug === $entry->getOriginal('slug')
                        && self::descendantsTooLong($type, $entry, $newParent, $slug)) {
                        $fail('hata::content.entry.descendants_too_long')->translate(['max' => EntrySlug::MAX_PATH]);
                    }
                })]
                : ['prohibited'],
            'author_id' => ['nullable', 'integer', function (string $attribute, mixed $value, Closure $fail) use ($type, $entry): void {
                $id = (int) $value;
                $isCurrent = $entry?->author_id !== null && $entry->author_id === $id;

                if (! $isCurrent && ! EntryAuthors::query($type)->whereKey($id)->exists()) {
                    $fail('hata::content.entry.author_invalid')->translate();
                }
            }],
            'image_id' => ['nullable', new MediaSelection('image')],
            'status' => ['required', Rule::enum(EntryStatus::class)],
            'published_at' => ['nullable', 'date'],
            'excerpt' => ['nullable', 'string', 'max:1000'],
            'seo_title' => ['nullable', 'string', 'max:255'],
            'seo_description' => ['nullable', 'string', 'max:500'],
            'body' => ['nullable', 'string', function (string $attribute, mixed $value, Closure $fail): void {
                if (is_string($value) && strlen($value) > self::BODY_MAX_BYTES) {
                    $fail('hata::content.entry.body_too_long')->translate();
                }
            }],
            'fields' => ['nullable', 'array'],
            // Terms: live records of the type's taxonomies in the record's language.
            'terms' => ['nullable', 'array', 'max:200', function (string $attribute, mixed $value, Closure $fail) use ($type, $locale): void {
                $ids = array_values(array_unique(array_map(intval(...), (array) $value)));

                if ($ids === []) {
                    return;
                }

                $found = Entry::query()
                    ->whereIn('type', array_keys(app(ContentTypes::class)->taxonomiesOf($type->key())))
                    ->where('locale', $locale)
                    ->whereKey($ids)
                    ->count();

                if ($found !== count($ids)) {
                    $fail('hata::content.entry.terms_invalid')->translate();
                }
            }],
            'terms.*' => ['integer'],
            'new_terms' => ['nullable', 'array'],
            'new_terms.*' => ['array', 'max:20'],
            'new_terms.*.*' => ['string', 'max:100'],
        ];

        foreach ($withSharedFields ? $type->fields() : $type->localFields() as $field) {
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
                // A record changing its language has no descendants there, and its old path means nothing in it.
                $ownPath = $entry?->exists && $entry->getOriginal('locale') === $locale ? (string) $entry->getOriginal('path') : null;

                if ($parent !== null && $ownPath !== null && ($parent->is($entry) || str_starts_with($parent->path, $ownPath.'/'))) {
                    $parent = null;
                }

                $found[$id] = $parent;
            }

            return $found[$id];
        };
    }

    /**
     * `EntrySlug` under the parent the data names, then (for a saved record whose slug changes) the paths its
     * descendants would get. While that parent is not a valid one, the slug is not checked: the parent has its own
     * error, and the path it would give means nothing.
     *
     * @param  Closure(mixed): ?Entry  $parent
     */
    private static function slugRule(ContentType $type, string $locale, ?Entry $entry, Closure $parent): ValidationRule
    {
        return self::dataRule(function (mixed $value, array $data, Closure $fail) use ($type, $locale, $entry, $parent): void {
            $parentId = $type->hierarchical() ? ($data['parent_id'] ?? null) : null;
            $newParent = blank($parentId) ? null : $parent($parentId);

            if (! blank($parentId) && $newParent === null) {
                return;
            }

            $failed = false;
            $track = function (string $message) use ($fail, &$failed): mixed {
                $failed = true;

                return $fail($message);
            };

            (new EntrySlug($type, $locale, $newParent, $entry))->validate('slug', $value, $track);

            if (! $failed && $entry?->exists && is_string($value) && $value !== $entry->getOriginal('slug')
                && self::descendantsTooLong($type, $entry, $newParent, $value)) {
                $fail('hata::content.slug.descendants_too_long')->translate(['max' => EntrySlug::MAX_PATH]);
            }
        });
    }

    /**
     * Whether moving the record under `$parent` with `$slug` would give one of its descendants (trashed ones
     * included) a path longer than EntrySlug::MAX_PATH. One query, for the longest descendant path.
     */
    private static function descendantsTooLong(ContentType $type, Entry $entry, ?Entry $parent, string $slug): bool
    {
        $oldPath = (string) $entry->getOriginal('path');
        $newPath = app(EntryPaths::class)->pathFor($type, $parent, $slug);

        if (! $type->hierarchical() || $newPath === $oldPath) {
            return false;
        }

        $longest = (int) app(EntryPaths::class)->scope($type, $entry->locale)
            ->where('path', 'like', $oldPath.'/%')
            ->max(DB::raw('LENGTH(path)'));

        return $longest > 0 && $longest - strlen($oldPath) + strlen($newPath) > EntrySlug::MAX_PATH;
    }

    /**
     * A rule that also sees the rest of the data under validation.
     *
     * @param  Closure(mixed, array<array-key, mixed>, Closure): void  $check
     */
    private static function dataRule(Closure $check): ValidationRule
    {
        return new class($check) implements DataAwareRule, ValidationRule
        {
            /**
             * @var array<array-key, mixed>
             */
            private array $data = [];

            /**
             * @param  Closure(mixed, array<array-key, mixed>, Closure): void  $check
             */
            public function __construct(private readonly Closure $check) {}

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
                ($this->check)($value, $this->data, $fail);
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

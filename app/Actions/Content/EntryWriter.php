<?php

namespace App\Actions\Content;

use App\Enums\EntryStatus;
use App\Models\Entry;
use App\Models\EntryGroup;
use App\Models\EntryRedirect;
use App\Models\MediaUsage;
use App\Support\Content\BodySanitizer;
use App\Support\Content\ContentType;
use App\Support\Content\ContentTypes;
use App\Support\Content\EntryInput;
use App\Support\Content\EntryPaths;
use App\Support\Content\Fields\Field;
use App\Support\Content\Fields\RichText;
use App\Support\Media\MediaIds;

/**
 * The writes CreateEntry and UpdateEntry share. Runs inside the caller's transaction.
 *
 * @phpstan-import-type EntryData from EntryInput
 */
class EntryWriter
{
    public function __construct(private readonly BodySanitizer $sanitizer, private readonly EntryPaths $paths) {}

    /**
     * Writes validated input to the record (never its type or language): the body sanitized, the date set to now
     * when publishing without one, per-language field values into `fields` and shared ones into the group, then
     * the path (a changed path of a saved record moves its descendants and keeps redirects) and the media usages.
     * A shared field missing from the input (EntryInput without shared fields) keeps the group's value, and when
     * none is given the group and its usages are not touched.
     *
     * @param  EntryData  $input
     */
    public function save(Entry $entry, ContentType $type, EntryGroup $group, array $input): void
    {
        $entry->fill([
            'title' => $input['title'],
            'slug' => $input['slug'],
            'parent_id' => $input['parent']?->id,
            'author_id' => $input['author_id'],
            'image_id' => $input['image_id'],
            'status' => $input['status'],
            'published_at' => $input['published_at'] ?? ($input['status'] === EntryStatus::Published ? now() : null),
            'excerpt' => $input['excerpt'],
            'seo_title' => $input['seo_title'],
            'seo_description' => $input['seo_description'],
            'body' => $this->sanitizer->clean($input['body']),
        ]);

        $local = [];

        foreach ($type->localFields() as $field) {
            $local[$field->name] = $field->toStorage($input['fields'][$field->name] ?? null);
        }

        $entry->fields = $local === [] ? null : $local;

        $path = $this->paths->pathFor($type, $input['parent'], $input['slug']);

        if ($entry->exists && $entry->path !== $path) {
            $this->paths->move($entry, $path);
        } else {
            if (! $entry->exists) {
                EntryRedirect::query()->where('type', $entry->type)->where('locale', $entry->locale)->where('path', $path)->delete();
            }

            $entry->path = $path;
            $entry->save();
        }

        $sharedWritten = $this->saveShared($group, $type, $input['fields']);
        $this->syncUsages($entry, $group, $type, $sharedWritten);
        $this->syncTerms($entry, $type, $input['terms'], $input['new_terms']);
    }

    /**
     * Files the record under the given terms and under those named in `$new`, which are found by name in the
     * record's language or made (a tag typed in the form). No query for a type without taxonomies.
     *
     * @param  list<int>  $ids
     * @param  array<string, list<string>>  $new
     */
    private function syncTerms(Entry $entry, ContentType $type, array $ids, array $new): void
    {
        $taxonomies = app(ContentTypes::class)->taxonomiesOf($type->key());

        if ($taxonomies === []) {
            return;
        }

        foreach ($new as $key => $names) {
            foreach ($names as $name) {
                $ids[] = $this->termNamed($taxonomies[$key], $entry->locale, $name)->id;
            }
        }

        // A term in the trash is not in the form, but its link stays: restoring the term brings the record back
        // under it.
        if ($entry->exists && ! $entry->wasRecentlyCreated) {
            $ids = [...$ids, ...$entry->terms()->onlyTrashed()->pluck('entries.id')->all()];
        }

        $entry->terms()->sync(array_values(array_unique($ids)));
    }

    /**
     * The live term of the taxonomy with that name in the language (whatever the case), else a new one: published,
     * in a group of its own, with a free slug.
     */
    private function termNamed(ContentType $taxonomy, string $locale, string $name): Entry
    {
        $wanted = mb_strtolower($name);

        $existing = Entry::query()
            ->ofType($taxonomy->key())
            ->where('locale', $locale)
            ->search($name)
            ->get()
            ->first(fn (Entry $term): bool => mb_strtolower($term->title) === $wanted);

        if ($existing !== null) {
            return $existing;
        }

        $slug = $this->paths->suggest($taxonomy, $locale, null, $name);
        $slug = $slug !== '' ? $slug : $this->paths->suggest($taxonomy, $locale, null, $taxonomy->key());

        $group = EntryGroup::query()->create(['type' => $taxonomy->key(), 'shared' => null]);

        return Entry::query()->create([
            'entry_group_id' => $group->id,
            'type' => $taxonomy->key(),
            'locale' => $locale,
            'title' => $name,
            'slug' => $slug,
            'path' => $this->paths->pathFor($taxonomy, null, $slug),
            'status' => EntryStatus::Published,
            'published_at' => now(),
        ]);
    }

    /**
     * Writes the shared fields the input carries into the group. Returns whether any was written.
     *
     * @param  array<string, mixed>  $fields
     */
    private function saveShared(EntryGroup $group, ContentType $type, array $fields): bool
    {
        $shared = [];

        foreach ($type->sharedFields() as $field) {
            if (array_key_exists($field->name, $fields)) {
                $shared[$field->name] = $field->toStorage($fields[$field->name]);
            }
        }

        if ($shared === []) {
            return false;
        }

        $group->shared = array_replace($group->shared ?? [], $shared);
        $group->save();

        return true;
    }

    /**
     * Records which files the record uses (`image`, `body`, `fields.<name>`) and which its group uses through
     * shared fields (`shared.<name>`, only when they were written). A new record with no files costs no query.
     */
    private function syncUsages(Entry $entry, EntryGroup $group, ContentType $type, bool $sharedWritten): void
    {
        $sync = function (Entry|EntryGroup $usable, string $field, array $ids): void {
            if ($ids !== [] || ! $usable->wasRecentlyCreated) {
                MediaUsage::sync($usable, $field, $ids);
            }
        };

        $sync($entry, 'image', $entry->image_id === null ? [] : [$entry->image_id]);
        $sync($entry, 'body', MediaIds::existing($this->sanitizer->mediaIds($entry->body)));

        foreach ($type->localFields() as $field) {
            if ($field->holdsMedia()) {
                $sync($entry, "fields.{$field->name}", $this->fieldMediaIds($field, $entry->fields[$field->name] ?? null));
            }
        }

        if ($sharedWritten) {
            $this->syncSharedUsages($group, $type);
        }
    }

    /**
     * Records which files the group uses through the type's shared fields (`shared.<name>`). A new group with no
     * files costs no query.
     */
    public function syncSharedUsages(EntryGroup $group, ContentType $type): void
    {
        foreach ($type->sharedFields() as $field) {
            if (! $field->holdsMedia()) {
                continue;
            }

            $ids = $this->fieldMediaIds($field, $group->shared[$field->name] ?? null);

            if ($ids !== [] || ! $group->wasRecentlyCreated) {
                MediaUsage::sync($group, "shared.{$field->name}", $ids);
            }
        }
    }

    /**
     * The files a field's stored value uses. A file field's ids were validated (MediaSelection); a rich text field's
     * images, like the body's, may name a file deleted meanwhile, so only existing ones count.
     *
     * @return list<int>
     */
    private function fieldMediaIds(Field $field, mixed $stored): array
    {
        $ids = $field->mediaIds($stored);

        return $field instanceof RichText ? MediaIds::existing($ids) : $ids;
    }
}

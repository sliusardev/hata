<?php

namespace App\Actions\Content;

use App\Enums\EntryStatus;
use App\Models\Entry;
use App\Models\EntryGroup;
use App\Models\EntryRedirect;
use App\Models\MediaUsage;
use App\Support\Content\BodySanitizer;
use App\Support\Content\ContentType;
use App\Support\Content\EntryInput;
use App\Support\Content\EntryPaths;
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

        $this->saveShared($group, $type, $input['fields']);
        $this->syncUsages($entry, $group, $type);
    }

    /**
     * @param  array<string, mixed>  $fields
     */
    private function saveShared(EntryGroup $group, ContentType $type, array $fields): void
    {
        $shared = [];

        foreach ($type->sharedFields() as $field) {
            $shared[$field->name] = $field->toStorage($fields[$field->name] ?? null);
        }

        if ($shared === []) {
            return;
        }

        $group->shared = array_replace($group->shared ?? [], $shared);
        $group->save();
    }

    /**
     * Records which files the record uses (`image`, `body`, `fields.<name>`) and which its group uses through
     * shared fields (`shared.<name>`). A new record with no files costs no query.
     */
    private function syncUsages(Entry $entry, EntryGroup $group, ContentType $type): void
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
                $sync($entry, "fields.{$field->name}", $field->mediaIds($entry->fields[$field->name] ?? null));
            }
        }

        foreach ($type->sharedFields() as $field) {
            if ($field->holdsMedia()) {
                $sync($group, "shared.{$field->name}", $field->mediaIds($group->shared[$field->name] ?? null));
            }
        }
    }
}

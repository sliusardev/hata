<?php

namespace App\Support\Content;

use App\Models\Entry;
use App\Models\EntryRedirect;
use App\Support\Localization\ReservedSlugs;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Slugs and paths of records: generating a free slug, building a path and moving a record (with its
 * descendants) to a new path while keeping the old ones as redirects.
 */
class EntryPaths
{
    public function __construct(private readonly ReservedSlugs $reserved, private readonly ContentTypes $types) {}

    /**
     * The slug a title gives; empty when the title has nothing to make one from.
     */
    public function slugFrom(string $title): string
    {
        return Str::slug($title);
    }

    /**
     * The slug of a title with `-2`, `-3` and so on added while its path is taken (by a record of the same type
     * and language, trashed or not, or by a reserved name at the top level). `$ignore` is the record being
     * edited, which does not take its own path.
     */
    public function suggest(ContentType $type, string $locale, ?Entry $parent, string $title, ?Entry $ignore = null): string
    {
        $base = $this->slugFrom($title);

        if ($base === '') {
            return '';
        }

        $prefix = $this->pathFor($type, $parent, $base);

        $taken = $this->scope($type, $locale, $ignore)
            ->where(fn (Builder $query) => $query->where('path', $prefix)->orWhere('path', 'like', $prefix.'-%'))
            ->pluck('path')
            ->flip();

        $slug = $base;

        for ($number = 2; $this->isReserved($type, $locale, $parent, $slug) || isset($taken[$this->pathFor($type, $parent, $slug)]); $number++) {
            $slug = $base.'-'.$number;
        }

        return $slug;
    }

    /**
     * Whether a slug may not be used because it would sit at the top of the address (no parent, and a type with
     * no base) and means something else: a reserved name, or the base of any registered type in that language.
     */
    public function isReserved(ContentType $type, string $locale, ?Entry $parent, string $slug): bool
    {
        if ($parent !== null || $type->base($locale) !== null) {
            return false;
        }

        if ($this->reserved->contains($slug)) {
            return true;
        }

        foreach ($this->types->all() as $registered) {
            if ($registered->base($locale) === $slug) {
                return true;
            }
        }

        return false;
    }

    public function pathFor(ContentType $type, ?Entry $parent, string $slug): string
    {
        return $parent === null ? $slug : $parent->path.'/'.$slug;
    }

    /**
     * Records of the type and language, trashed ones included, except `$ignore`.
     *
     * @return Builder<Entry>
     */
    public function scope(ContentType $type, string $locale, ?Entry $ignore = null): Builder
    {
        return Entry::query()
            ->withTrashed()
            ->where('type', $type->key())
            ->where('locale', $locale)
            ->when($ignore?->exists, fn (Builder $query) => $query->whereKeyNot($ignore->getKey()));
    }

    /**
     * Gives the record a new path and every descendant (same type and language, trashed ones included) the new
     * prefix, stores the old paths as redirects, and removes the redirects whose path a record now holds. Runs
     * inside the caller's transaction, in a fixed number of queries whatever the number of descendants.
     */
    public function move(Entry $entry, string $newPath): void
    {
        $oldPath = $entry->path;

        if ($oldPath === $newPath) {
            return;
        }

        $scope = fn () => Entry::query()->withTrashed()->where('type', $entry->type)->where('locale', $entry->locale);

        $moved = $scope()
            ->where(fn (Builder $query) => $query->whereKey($entry->getKey())->orWhere('path', 'like', $oldPath.'/%'))
            ->pluck('path', 'id');

        $entry->path = $newPath;
        $entry->save();

        DB::update(
            'UPDATE '.$entry->getTable().' SET path = '.$this->prefixSwap($oldPath).' WHERE type = ? AND locale = ? AND path LIKE ?',
            [$newPath, $entry->type, $entry->locale, $oldPath.'/%'],
        );

        EntryRedirect::query()
            ->where('type', $entry->type)
            ->where('locale', $entry->locale)
            ->whereIn('path', $moved->map(fn (string $path): string => $newPath.substr($path, strlen($oldPath)))->values())
            ->delete();

        $now = now();

        EntryRedirect::upsert(
            $moved->map(fn (string $path, int $id): array => [
                'type' => $entry->type,
                'locale' => $entry->locale,
                'path' => $path,
                'entry_id' => $id,
                'created_at' => $now,
                'updated_at' => $now,
            ])->values()->all(),
            ['type', 'locale', 'path'],
            ['entry_id', 'updated_at'],
        );
    }

    /**
     * The SQL value of a descendant's new path: the new prefix (the first binding), then what follows the old
     * one. The only driver-specific part of `move()`.
     */
    private function prefixSwap(string $oldPath): string
    {
        $rest = 'SUBSTR(path, '.(strlen($oldPath) + 1).')';

        return DB::connection()->getDriverName() === 'sqlite' ? "? || {$rest}" : "CONCAT(?, {$rest})";
    }
}

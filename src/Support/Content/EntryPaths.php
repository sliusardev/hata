<?php

namespace Hata\Support\Content;

use Hata\Models\Entry;
use Hata\Models\EntryRedirect;
use Hata\Support\Localization\ReservedSlugs;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

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
     * `$keepOldPaths = false` stores no redirects: for a record restored from the trash, whose old paths answered
     * nothing while it was there.
     */
    public function move(Entry $entry, string $newPath, bool $keepOldPaths = true): void
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

        // The table name through the grammar, so a table prefix of the connection applies as to every other query.
        DB::update(
            'UPDATE '.DB::connection()->getQueryGrammar()->wrapTable($entry->getTable()).' SET path = '.$this->prefixSwap($oldPath).', updated_at = ? WHERE type = ? AND locale = ? AND path LIKE ?',
            [$newPath, now(), $entry->type, $entry->locale, $oldPath.'/%'],
        );

        EntryRedirect::query()
            ->where('type', $entry->type)
            ->where('locale', $entry->locale)
            ->whereIn('path', $moved->map(fn (string $path): string => $newPath.substr($path, strlen($oldPath)))->values())
            ->delete();

        if (! $keepOldPaths) {
            return;
        }

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
     * Makes a record without descendants one of another language, at a path there: its old address stays as a
     * redirect, and a redirect that held the new address goes. Only sets the record's language and path; the
     * caller saves it, inside its transaction.
     */
    public function relocate(Entry $entry, string $locale, string $path): void
    {
        $now = now();

        EntryRedirect::upsert(
            [[
                'type' => $entry->type,
                'locale' => $entry->locale,
                'path' => $entry->path,
                'entry_id' => $entry->id,
                'created_at' => $now,
                'updated_at' => $now,
            ]],
            ['type', 'locale', 'path'],
            ['entry_id', 'updated_at'],
        );

        EntryRedirect::query()->where('type', $entry->type)->where('locale', $locale)->where('path', $path)->delete();

        $entry->locale = $locale;
        $entry->path = $path;
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

    /**
     * For the caller's transaction, before it writes a record: locks the record's row and its parent's (in id order,
     * so two saves never wait on each other's lock) and checks what validation saw is still true. The record must
     * still be live with the language and path the caller loaded; the parent must still be a live record of the
     * language at the path the record's new path was built from, and not the record or anything under it. Two
     * requests can otherwise put a page under a parent that has just left the language, or two pages under each
     * other.
     *
     * @throws ValidationException when the parent is no longer valid (`parent_id`) or the record changed (`slug`)
     */
    public function lockForSave(?Entry $entry, ?Entry $parent, string $locale): void
    {
        $ids = array_values(array_filter([$entry?->id, $parent?->id]));

        if ($ids === []) {
            return;
        }

        $rows = Entry::query()->whereKey($ids)->orderBy('id')->lockForUpdate()->get(['id', 'locale', 'path'])->keyBy('id');
        $ownPath = null;

        if ($entry?->exists) {
            $own = $rows->get($entry->id);

            if ($own === null || $own->locale !== $entry->getOriginal('locale') || $own->path !== $entry->getOriginal('path')) {
                throw ValidationException::withMessages(['slug' => __('hata::content.entry.changed_meanwhile')]);
            }

            // In another language the record's old path means nothing (it has no descendants there).
            $ownPath = $own->locale === $locale ? $own->path : null;
        }

        if ($parent === null) {
            return;
        }

        $fresh = $rows->get($parent->id);

        if ($fresh === null
            || $fresh->locale !== $locale
            || $fresh->path !== $parent->path
            || ($ownPath !== null && ($fresh->path === $ownPath || str_starts_with($fresh->path, $ownPath.'/')))) {
            throw ValidationException::withMessages(['parent_id' => __('hata::content.entry.parent_invalid')]);
        }
    }
}

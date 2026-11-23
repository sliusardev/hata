<?php

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Keeps a lowercased "search_index" column in sync for case-insensitive search that behaves
 * the same on SQLite and MySQL, including non-ASCII (e.g. Cyrillic) text.
 *
 * @phpstan-require-extends Model
 */
trait HasSearchIndex
{
    /**
     * The text that search_index is built from.
     */
    abstract protected function searchIndexSource(): string;

    public static function bootHasSearchIndex(): void
    {
        static::saving(function (self $model): void {
            $model->setAttribute('search_index', mb_strtolower($model->searchIndexSource()));
        });
    }

    /**
     * Limit the query to records whose searchable text contains the term, ignoring case.
     *
     * @param  Builder<static>  $query
     */
    public function scopeSearch(Builder $query, ?string $term): void
    {
        $term = trim((string) $term);

        if ($term === '') {
            return;
        }

        $query->where('search_index', 'like', '%'.mb_strtolower($term).'%');
    }
}

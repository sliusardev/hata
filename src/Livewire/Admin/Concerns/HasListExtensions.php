<?php

namespace Hata\Livewire\Admin\Concerns;

use Closure;
use Hata\Support\Admin\Lists\ListContext;
use Hata\Support\Admin\Lists\TableColumn;
use Hata\Support\Admin\Lists\TableColumns;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;

/**
 * What an admin list page takes from the registries: the columns the signed-in user sees, their eager loads and
 * their sorts. The page names its target in `listContext()`.
 *
 * @property-read list<TableColumn> $listColumns
 */
trait HasListExtensions
{
    abstract protected function listContext(): ListContext;

    /**
     * @return list<TableColumn>
     */
    #[Computed]
    public function listColumns(): array
    {
        return app(TableColumns::class)->for(Auth::user(), $this->listContext());
    }

    /**
     * Adds every visible column's eager loads to the page's query, once.
     *
     * @param  Builder<*>  $query
     * @return Builder<*>
     */
    protected function applyColumnLoads(Builder $query): Builder
    {
        foreach ($this->listColumns as $column) {
            if ($column->with instanceof Closure) {
                ($column->with)($query);
            } elseif ($column->with !== []) {
                $query->with($column->with);
            }
        }

        return $query;
    }

    /**
     * The sort of a visible, sortable column, or null.
     */
    protected function registeredSort(string $key): ?Closure
    {
        foreach ($this->listColumns as $column) {
            if ($column->key === $key) {
                return $column->sort;
            }
        }

        return null;
    }
}

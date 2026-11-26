<?php

namespace Hata\Livewire\Admin\Concerns;

use Closure;
use Hata\Support\Admin\Lists\ListContext;
use Hata\Support\Admin\Lists\TableColumn;
use Hata\Support\Admin\Lists\TableColumns;
use Hata\Support\Admin\Lists\TableFilter;
use Hata\Support\Admin\Lists\TableFilters;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;

/**
 * What an admin list page takes from the registries: the columns the signed-in user sees, their eager loads and
 * their sorts, and the filters. The page names its target in `listContext()`.
 *
 * @property-read list<TableColumn> $listColumns
 * @property-read list<TableFilter> $listFilters
 * @property-read array<string, array<array-key, string>> $listFilterOptions
 */
trait HasListExtensions
{
    /**
     * The chosen value of each registered filter, by key (`?filter[key]=value`).
     *
     * @var array<string, mixed>
     */
    #[Url(as: 'filter', except: [])]
    public array $filters = [];

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

    /**
     * @return list<TableFilter>
     */
    #[Computed]
    public function listFilters(): array
    {
        return app(TableFilters::class)->for(Auth::user(), $this->listContext());
    }

    /**
     * The options of every visible filter, by filter key; each filter's closure runs once per render.
     *
     * @return array<string, array<array-key, string>>
     */
    #[Computed]
    public function listFilterOptions(): array
    {
        $context = $this->listContext();
        $options = [];

        foreach ($this->listFilters as $filter) {
            $options[$filter->key] = ($filter->options)($context);
        }

        return $options;
    }

    /**
     * Narrows the page's query by every visible filter whose value is one of its options.
     *
     * @param  Builder<*>  $query
     * @return Builder<*>
     */
    protected function applyFilters(Builder $query): Builder
    {
        foreach ($this->listFilters as $filter) {
            $value = $this->filters[$filter->key] ?? '';

            if (is_string($value) && $value !== '' && array_key_exists($value, $this->listFilterOptions[$filter->key])) {
                ($filter->apply)($query, $value);
            }
        }

        return $query;
    }

    /**
     * Whether a registered filter has a value.
     */
    protected function hasListFilterValue(): bool
    {
        return array_filter($this->filters, fn (mixed $value): bool => $value !== '' && $value !== null) !== [];
    }

    public function updatedFilters(): void
    {
        $this->resetPage();
    }
}

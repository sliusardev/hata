<?php

namespace Hata\Support\Admin\Lists;

use Hata\Support\Admin\Registry\Entry;
use Hata\Support\Admin\Registry\Registry;

/**
 * What one list reads from `TableFilters`: the filters resolved for its target.
 *
 * @extends Registry<TableFilter>
 */
final class TableFilterRegistry extends Registry
{
    public function name(): string
    {
        return 'list filter';
    }

    public function describe(Entry $entry): string
    {
        return $entry->visibleIn !== null ? 'tab-dependent' : 'every tab';
    }

    /**
     * Filter keys are `?filter[key]=` values and Livewire model paths, so they hold no dots.
     */
    protected function keyPattern(): string
    {
        return '/^[a-z0-9]+([_-][a-z0-9]+)*\z/';
    }

    protected function keyProblem(): string
    {
        return 'its key is not lowercase letters and digits joined by hyphens or underscores';
    }
}

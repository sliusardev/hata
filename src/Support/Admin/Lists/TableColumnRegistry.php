<?php

namespace Hata\Support\Admin\Lists;

use Hata\Support\Admin\Registry\Entry;
use Hata\Support\Admin\Registry\Registry;

/**
 * What one list reads from `TableColumns`: the columns resolved for its target.
 *
 * @extends Registry<TableColumn>
 */
final class TableColumnRegistry extends Registry
{
    public function name(): string
    {
        return 'list column';
    }

    public function describe(Entry $entry): string
    {
        return ($entry->sort !== null ? 'sortable' : 'not sortable').($entry->hideBelow !== null ? ", hidden below {$entry->hideBelow}" : '');
    }

    /**
     * Column keys are the `?sortBy=` values, so they may hold underscores (`created_at`).
     */
    protected function keyPattern(): string
    {
        return '/^[a-z0-9]+([._-][a-z0-9]+)*\z/';
    }

    protected function keyProblem(): string
    {
        return 'its key is not lowercase letters and digits joined by hyphens, underscores or dots';
    }

    protected function validate(Entry $entry): ?string
    {
        if ($entry->hideBelow !== null && ! in_array($entry->hideBelow, TableColumn::HIDE_BELOW, true)) {
            return "its hideBelow [{$entry->hideBelow}] is not md or lg";
        }

        if (! in_array($entry->align, TableColumn::ALIGNS, true)) {
            return "its align [{$entry->align}] is not start, center or end";
        }

        return null;
    }
}

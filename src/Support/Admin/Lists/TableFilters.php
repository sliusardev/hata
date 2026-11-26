<?php

namespace Hata\Support\Admin\Lists;

use Hata\Contracts\AdminUser;
use Hata\Support\Admin\Registry\Registry;
use Hata\Support\Admin\Registry\TargetedRegistry;

/**
 * The filters of the admin lists, aimed with `Admin::filters('users')` (or `content.*`, `content.posts`, …).
 *
 * @extends TargetedRegistry<TableFilterRegistry>
 */
final class TableFilters extends TargetedRegistry
{
    public function name(): string
    {
        return 'list filter';
    }

    /**
     * The filters this user sees on this list, in order.
     *
     * @return list<TableFilter>
     */
    public function for(AdminUser $user, ListContext $context): array
    {
        $registry = $this->resolve($context->target);

        return array_values(array_filter(
            $registry->entriesFor($user, $context),
            fn (TableFilter $filter): bool => $filter->visibleIn === null || (bool) ($filter->visibleIn)($context),
        ));
    }

    protected function newRegistry(): Registry
    {
        return new TableFilterRegistry;
    }
}

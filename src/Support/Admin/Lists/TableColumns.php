<?php

namespace Hata\Support\Admin\Lists;

use Hata\Contracts\AdminUser;
use Hata\Support\Admin\Registry\Registry;
use Hata\Support\Admin\Registry\TargetedRegistry;

/**
 * The columns of the admin lists, aimed with `Admin::columns('users')` (or `content.*`, `content.posts`, …).
 *
 * @extends TargetedRegistry<TableColumnRegistry>
 */
final class TableColumns extends TargetedRegistry
{
    public function name(): string
    {
        return 'list column';
    }

    /**
     * The columns this user sees on this list, in order.
     *
     * @return list<TableColumn>
     */
    public function for(AdminUser $user, ListContext $context): array
    {
        $registry = $this->resolve($context->target);

        return array_values(array_filter(
            $registry->entriesFor($user, $context),
            fn (TableColumn $column): bool => $column->visibleIn === null || (bool) ($column->visibleIn)($context),
        ));
    }

    protected function newRegistry(): Registry
    {
        return new TableColumnRegistry;
    }
}

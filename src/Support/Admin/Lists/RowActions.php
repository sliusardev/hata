<?php

namespace Hata\Support\Admin\Lists;

use Hata\Contracts\AdminUser;
use Hata\Support\Admin\Registry\Registry;
use Hata\Support\Admin\Registry\TargetedRegistry;

/**
 * The "⋮" menu items of the admin lists, aimed with `Admin::rowActions('users')` (or `content.*`, `content.posts`, …).
 *
 * @extends TargetedRegistry<RowActionRegistry>
 */
final class RowActions extends TargetedRegistry
{
    public function name(): string
    {
        return 'row action';
    }

    /**
     * The actions this list shows, in order: those visible in its tab (by default every tab but the trash). Whether
     * a user may take one on a given record is `RowAction::allows()`.
     *
     * @return list<RowAction>
     */
    public function for(AdminUser $user, ListContext $context): array
    {
        return array_values(array_filter(
            $this->resolve($context->target)->entriesFor($user),
            fn (RowAction $action): bool => $action->visibleIn !== null ? (bool) ($action->visibleIn)($context) : ! $context->isTrash(),
        ));
    }

    protected function newRegistry(): Registry
    {
        return new RowActionRegistry;
    }
}

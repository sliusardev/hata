<?php

namespace Hata\Support\Admin\Lists;

use Hata\Contracts\AdminUser;
use Hata\Support\Admin\Registry\Registry;
use Hata\Support\Admin\Registry\TargetedRegistry;

/**
 * The bulk bar buttons of the admin lists, aimed with `Admin::bulkActions('users')` (or `content.*`, `content.posts`, …).
 *
 * @extends TargetedRegistry<BulkActionRegistry>
 */
final class BulkActions extends TargetedRegistry
{
    public function name(): string
    {
        return 'bulk action';
    }

    /**
     * The actions this list shows the user, in order: those they have the permission for and that are visible in the
     * list's tab (by default every tab but the trash).
     *
     * @return list<BulkAction>
     */
    public function for(AdminUser $user, ListContext $context): array
    {
        return array_values(array_filter(
            $this->resolve($context->target)->entriesFor($user),
            fn (BulkAction $action): bool => $action->visibleIn !== null ? (bool) ($action->visibleIn)($context) : ! $context->isTrash(),
        ));
    }

    protected function newRegistry(): Registry
    {
        return new BulkActionRegistry;
    }
}

<?php

namespace Hata\Policies;

use Hata\Contracts\AdminUser;

/**
 * Permission checks for managing users. Protection rules that also bind super admins
 * (rules 2–5) live in Hata\Support\Permissions\AccessRules and are enforced by the actions.
 */
class UserPolicy
{
    public function viewAny(AdminUser $actor): bool
    {
        return $actor->can('users.view');
    }

    public function view(AdminUser $actor, AdminUser $user): bool
    {
        return $actor->can('users.view');
    }

    public function create(AdminUser $actor): bool
    {
        return $actor->can('users.create');
    }

    public function update(AdminUser $actor, AdminUser $user): bool
    {
        return $actor->can('users.edit');
    }

    public function delete(AdminUser $actor, AdminUser $user): bool
    {
        return $actor->can('users.delete');
    }
}

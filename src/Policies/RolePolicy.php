<?php

namespace Hata\Policies;

use Hata\Contracts\AdminUser;
use Hata\Models\Role;
use Hata\Support\Permissions\AccessRules;

/**
 * Permission checks for managing roles. Protection rules that also bind super admins
 * live in Hata\Support\Permissions\AccessRules and are enforced by the actions.
 */
class RolePolicy
{
    public function __construct(private AccessRules $rules) {}

    public function viewAny(AdminUser $actor): bool
    {
        return $actor->can('roles.view');
    }

    public function view(AdminUser $actor, Role $role): bool
    {
        return $actor->can('roles.view') || $actor->can('roles.edit');
    }

    public function create(AdminUser $actor): bool
    {
        return $actor->can('roles.create');
    }

    public function update(AdminUser $actor, Role $role): bool
    {
        return $actor->can('roles.edit');
    }

    public function delete(AdminUser $actor, Role $role): bool
    {
        return $actor->can('roles.delete');
    }

    /**
     * Whether the actor may assign the role to users (rules 2 and 5).
     */
    public function assign(AdminUser $actor, Role $role): bool
    {
        return $this->rules->canAssignRole($actor, $role);
    }
}

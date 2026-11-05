<?php

namespace App\Policies;

use App\Models\Role;
use App\Models\User;
use App\Support\Permissions\AccessRules;

/**
 * Permission checks for managing roles. Protection rules that also bind super admins
 * live in App\Support\Permissions\AccessRules and are enforced by the actions.
 */
class RolePolicy
{
    public function __construct(private AccessRules $rules) {}

    public function viewAny(User $actor): bool
    {
        return $actor->can('roles.view');
    }

    public function view(User $actor, Role $role): bool
    {
        return $actor->can('roles.view') || $actor->can('roles.edit');
    }

    public function create(User $actor): bool
    {
        return $actor->can('roles.create');
    }

    public function update(User $actor, Role $role): bool
    {
        return $actor->can('roles.edit');
    }

    public function delete(User $actor, Role $role): bool
    {
        return $actor->can('roles.delete');
    }

    /**
     * Whether the actor may assign the role to users (rules 2 and 5).
     */
    public function assign(User $actor, Role $role): bool
    {
        return $this->rules->canAssignRole($actor, $role);
    }
}

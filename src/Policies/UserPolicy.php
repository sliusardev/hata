<?php

namespace App\Policies;

use App\Models\User;

/**
 * Permission checks for managing users. Protection rules that also bind super admins
 * (rules 2–5) live in App\Support\Permissions\AccessRules and are enforced by the actions.
 */
class UserPolicy
{
    public function viewAny(User $actor): bool
    {
        return $actor->can('users.view');
    }

    public function view(User $actor, User $user): bool
    {
        return $actor->can('users.view');
    }

    public function create(User $actor): bool
    {
        return $actor->can('users.create');
    }

    public function update(User $actor, User $user): bool
    {
        return $actor->can('users.edit');
    }

    public function delete(User $actor, User $user): bool
    {
        return $actor->can('users.delete');
    }
}

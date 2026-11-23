<?php

namespace App\Support\Permissions;

use App\Enums\SystemRole;
use App\Models\Role;
use App\Models\User;
use Spatie\Permission\Models\Permission;

/**
 * The protection rules for users, roles and permissions.
 *
 * Actions enforce these rules (they apply to super admins too, unlike Gate checks, which
 * super admins always pass). The interface uses the same methods to hide or disable controls.
 */
class AccessRules
{
    /**
     * Whether the actor may assign the role to a user, or remove it from one.
     *
     * Only super admins handle the super-admin role (rule 2). Everyone else may only assign
     * roles whose permissions they all have themselves (rule 5).
     */
    public function canAssignRole(User $actor, Role $role): bool
    {
        if ($actor->isSuperAdmin()) {
            return true;
        }

        if ($role->isSuperAdmin()) {
            return false;
        }

        return $role->permissions->pluck('name')
            ->diff($this->permissionNamesOf($actor))
            ->isEmpty();
    }

    /**
     * Whether the actor may add the permission to a role (rule 5).
     */
    public function canGrantPermission(User $actor, string $permission): bool
    {
        return $actor->isSuperAdmin() || in_array($permission, $this->permissionNamesOf($actor), true);
    }

    /**
     * Whether the user is the only remaining super admin (rule 3).
     */
    public function isLastSuperAdmin(User $user): bool
    {
        if (! $user->isSuperAdmin()) {
            return false;
        }

        return User::role(SystemRole::SuperAdmin->value)->whereKeyNot($user->getKey())->doesntExist();
    }

    /**
     * How many super admins there are, for userDeletionDenial() in a list.
     */
    public function superAdminCount(): int
    {
        return User::role(SystemRole::SuperAdmin->value)->count();
    }

    /**
     * The translation key explaining why the actor may not edit the user, or null when allowed.
     *
     * Only super admins edit super admins: changing their email or password would let
     * anyone else take over the account (follows from rule 2).
     */
    public function userUpdateDenial(User $actor, User $user): ?string
    {
        return $user->isSuperAdmin() && ! $actor->isSuperAdmin()
            ? 'users.errors.cannot_manage_super_admin'
            : null;
    }

    /**
     * The translation key explaining why the actor may not delete the user, or null when allowed.
     *
     * A list that asks for many users passes $superAdminCount (counted once), so the "last super admin" rule
     * costs no query per row; without it the rule asks the database.
     */
    public function userDeletionDenial(User $actor, User $user, ?int $superAdminCount = null): ?string
    {
        $isLastSuperAdmin = $superAdminCount === null
            ? $this->isLastSuperAdmin($user)
            : $user->isSuperAdmin() && $superAdminCount <= 1;

        return match (true) {
            $actor->is($user) => 'users.errors.cannot_delete_self',
            $isLastSuperAdmin => 'users.errors.cannot_delete_last_super_admin',
            $user->isSuperAdmin() && ! $actor->isSuperAdmin() => 'users.errors.cannot_manage_super_admin',
            default => null,
        };
    }

    /**
     * The translation key explaining why the role's name and permissions cannot be changed,
     * or null when they can (rule 1: the super-admin role is fixed).
     */
    public function roleUpdateDenial(Role $role): ?string
    {
        return $role->isSuperAdmin() ? 'roles.errors.super_admin_locked' : null;
    }

    /**
     * The translation key explaining why the role cannot be deleted, or null when it can.
     *
     * Rule 1: never super-admin. Rule 6: never a system role. Rule 7: never a role in use.
     */
    public function roleDeletionDenial(Role $role): ?string
    {
        return match (true) {
            $role->isSuperAdmin() => 'roles.errors.super_admin_locked',
            $role->is_system => 'roles.errors.cannot_delete_system',
            $role->users()->exists() => 'roles.errors.cannot_delete_in_use',
            default => null,
        };
    }

    /**
     * @return array<int, string>
     */
    private function permissionNamesOf(User $user): array
    {
        return $user->getAllPermissions()->map(fn (Permission $permission): string => $permission->name)->all();
    }
}

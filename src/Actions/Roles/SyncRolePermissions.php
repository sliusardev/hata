<?php

namespace Hata\Actions\Roles;

use App\Models\User;
use Hata\Exceptions\ProtectedActionException;
use Hata\Models\Role;
use Hata\Support\Permissions\AccessRules;
use Hata\Support\Permissions\PermissionRegistry;
use InvalidArgumentException;

/**
 * Replace the permissions of a role, enforcing the protection rules.
 *
 * Callers authorize the surrounding operation (roles.create or roles.edit).
 */
class SyncRolePermissions
{
    public function __construct(
        private AccessRules $rules,
        private PermissionRegistry $registry,
    ) {}

    /**
     * @param  list<string>  $permissions  Validated permission names.
     *
     * @throws ProtectedActionException
     */
    public function handle(User $actor, Role $role, array $permissions): Role
    {
        if ($denial = $this->rules->roleUpdateDenial($role)) {
            throw ProtectedActionException::because($denial);
        }

        $target = array_values(array_unique($permissions));

        if ($unknown = array_diff($target, $this->registry->all())) {
            throw new InvalidArgumentException('Unknown permissions: '.implode(', ', $unknown));
        }

        $current = $role->permissions()->pluck('name')->all();

        foreach (array_diff($target, $current) as $added) {
            if (! $this->rules->canGrantPermission($actor, $added)) {
                throw ProtectedActionException::because('roles.errors.cannot_grant_permission', [
                    'permission' => __("permissions.items.{$added}"),
                ]);
            }
        }

        $role->syncPermissions($target);

        return $role->load('permissions');
    }
}

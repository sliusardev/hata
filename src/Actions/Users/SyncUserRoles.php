<?php

namespace Hata\Actions\Users;

use App\Models\User;
use Hata\Exceptions\ProtectedActionException;
use Hata\Models\Role;
use Hata\Support\Permissions\AccessRules;
use Illuminate\Support\Facades\DB;

/**
 * Replace the roles of a user, enforcing the protection rules.
 *
 * Callers authorize the surrounding operation (users.create or users.edit).
 */
class SyncUserRoles
{
    public function __construct(private AccessRules $rules) {}

    /**
     * @param  list<int>  $roleIds  Validated ids of existing roles.
     *
     * @throws ProtectedActionException
     */
    public function handle(User $actor, User $user, array $roleIds): User
    {
        $currentIds = $user->roles()->pluck('id')->map(fn (mixed $id): int => (int) $id)->all();
        $targetIds = array_values(array_unique(array_map('intval', $roleIds)));

        $addedIds = array_values(array_diff($targetIds, $currentIds));
        $removedIds = array_values(array_diff($currentIds, $targetIds));

        if ($addedIds === [] && $removedIds === []) {
            return $user;
        }

        if ($actor->is($user)) {
            throw ProtectedActionException::because('hata::users.errors.cannot_change_own_roles');
        }

        $changedRoles = Role::with('permissions')->whereKey([...$addedIds, ...$removedIds])->get();

        foreach ($changedRoles as $role) {
            if (in_array($role->id, $removedIds, true) && $role->isSuperAdmin() && $this->rules->isLastSuperAdmin($user)) {
                throw ProtectedActionException::because('hata::users.errors.cannot_remove_last_super_admin');
            }

            if ($role->isSuperAdmin() && ! $actor->isSuperAdmin()) {
                throw ProtectedActionException::because('hata::users.errors.cannot_manage_super_admin');
            }

            if (in_array($role->id, $addedIds, true) && ! $this->rules->canAssignRole($actor, $role)) {
                throw ProtectedActionException::because('hata::users.errors.cannot_assign_role', ['role' => $role->displayName()]);
            }
        }

        DB::transaction(fn () => $user->syncRoles(Role::whereKey($targetIds)->get()));

        return $user->load('roles');
    }
}

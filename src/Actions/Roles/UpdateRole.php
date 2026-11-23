<?php

namespace App\Actions\Roles;

use App\Exceptions\ProtectedActionException;
use App\Models\Role;
use App\Models\User;
use App\Support\Permissions\AccessRules;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class UpdateRole
{
    public function __construct(
        private AccessRules $rules,
        private SyncRolePermissions $syncRolePermissions,
    ) {}

    /**
     * The label of system roles is not changed: they are shown by their translated name.
     *
     * @param  array{label?: string|null, permissions: list<string>}  $data  Validated input.
     *
     * @throws ProtectedActionException
     */
    public function handle(User $actor, Role $role, array $data): Role
    {
        Gate::forUser($actor)->authorize('update', $role);

        if ($denial = $this->rules->roleUpdateDenial($role)) {
            throw ProtectedActionException::because($denial);
        }

        return DB::transaction(function () use ($actor, $role, $data): Role {
            if (! $role->is_system && filled($data['label'] ?? null)) {
                $role->update(['label' => $data['label']]);
            }

            return $this->syncRolePermissions->handle($actor, $role, $data['permissions']);
        });
    }
}

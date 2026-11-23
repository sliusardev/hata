<?php

namespace Hata\Actions\Roles;

use App\Models\User;
use Hata\Exceptions\ProtectedActionException;
use Hata\Models\Role;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class CreateRole
{
    public function __construct(private SyncRolePermissions $syncRolePermissions) {}

    /**
     * @param  array{label: string, name: string, permissions: list<string>}  $data  Validated input.
     *
     * @throws ProtectedActionException
     */
    public function handle(User $actor, array $data): Role
    {
        Gate::forUser($actor)->authorize('create', Role::class);

        return DB::transaction(function () use ($actor, $data): Role {
            $role = new Role([
                'name' => $data['name'],
                'label' => $data['label'],
                'guard_name' => 'web',
                'is_system' => false,
            ]);
            $role->save();

            return $this->syncRolePermissions->handle($actor, $role, $data['permissions']);
        });
    }
}

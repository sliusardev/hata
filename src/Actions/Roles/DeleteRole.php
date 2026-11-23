<?php

namespace Hata\Actions\Roles;

use App\Models\User;
use Hata\Exceptions\ProtectedActionException;
use Hata\Models\Role;
use Hata\Support\Permissions\AccessRules;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class DeleteRole
{
    public function __construct(private AccessRules $rules) {}

    /**
     * @throws ProtectedActionException
     */
    public function handle(User $actor, Role $role): void
    {
        Gate::forUser($actor)->authorize('delete', $role);

        if ($denial = $this->rules->roleDeletionDenial($role)) {
            throw ProtectedActionException::because($denial, ['users' => trans_choice('hata::users.count', $role->users()->count())]);
        }

        DB::transaction(fn () => $role->delete());
    }
}

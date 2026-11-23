<?php

namespace Hata\Actions\Users;

use App\Models\User;
use Hata\Exceptions\ProtectedActionException;
use Hata\Support\Permissions\AccessRules;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class DeleteUser
{
    public function __construct(private AccessRules $rules) {}

    /**
     * @throws ProtectedActionException
     */
    public function handle(User $actor, User $user): void
    {
        Gate::forUser($actor)->authorize('delete', $user);

        if ($denial = $this->rules->userDeletionDenial($actor, $user)) {
            throw ProtectedActionException::because($denial);
        }

        DB::transaction(fn () => $user->delete());
    }
}

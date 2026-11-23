<?php

namespace Hata\Actions\Users;

use Hata\Contracts\AdminUser;
use Hata\Exceptions\ProtectedActionException;
use Hata\Support\Permissions\AccessRules;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

class UpdateUser
{
    public function __construct(
        private AccessRules $rules,
        private SyncUserRoles $syncUserRoles,
    ) {}

    /**
     * Roles are only synced when the "roles" key is present.
     *
     * @param  array{name: string, email: string, locale: string, password?: string|null, roles?: list<int>}  $data  Validated input.
     *
     * @throws ProtectedActionException
     */
    public function handle(AdminUser $actor, AdminUser $user, array $data): AdminUser
    {
        Gate::forUser($actor)->authorize('update', $user);

        if ($denial = $this->rules->userUpdateDenial($actor, $user)) {
            throw ProtectedActionException::because($denial);
        }

        return DB::transaction(function () use ($actor, $user, $data): AdminUser {
            $user->fill([
                'name' => $data['name'],
                'email' => Str::lower($data['email']),
                'locale' => $data['locale'],
            ]);

            if (filled($data['password'] ?? null)) {
                $user->password = $data['password'];
            }

            $user->save();

            if (array_key_exists('roles', $data)) {
                $this->syncUserRoles->handle($actor, $user, $data['roles']);
            }

            return $user;
        });
    }
}

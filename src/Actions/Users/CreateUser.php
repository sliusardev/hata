<?php

namespace Hata\Actions\Users;

use Hata\Contracts\AdminUser;
use Hata\Hata;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

class CreateUser
{
    public function __construct(private SyncUserRoles $syncUserRoles) {}

    /**
     * @param  array{name: string, email: string, password: string, locale: string, roles: list<int>, email_verified: bool}  $data  Validated input.
     */
    public function handle(AdminUser $actor, array $data): AdminUser
    {
        Gate::forUser($actor)->authorize('create', Hata::userModel());

        return DB::transaction(function () use ($actor, $data): AdminUser {
            $user = new (Hata::userModel())([
                'name' => $data['name'],
                'email' => Str::lower($data['email']),
                'password' => $data['password'],
                'locale' => $data['locale'],
            ]);
            $user->forceFill(['email_verified_at' => $data['email_verified'] ? now() : null])->save();

            return $this->syncUserRoles->handle($actor, $user, $data['roles']);
        });
    }
}

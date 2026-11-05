<?php

namespace App\Actions\Users;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

class CreateUser
{
    public function __construct(private SyncUserRoles $syncUserRoles) {}

    /**
     * @param  array{name: string, email: string, password: string, locale: string, roles: list<int>, email_verified: bool}  $data  Validated input.
     */
    public function handle(User $actor, array $data): User
    {
        Gate::forUser($actor)->authorize('create', User::class);

        return DB::transaction(function () use ($actor, $data): User {
            $user = new User([
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

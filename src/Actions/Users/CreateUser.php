<?php

namespace Hata\Actions\Users;

use Closure;
use Hata\Contracts\AdminUser;
use Hata\Exceptions\FormCardFailed;
use Hata\Hata;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

class CreateUser
{
    public function __construct(private SyncUserRoles $syncUserRoles) {}

    /**
     * @param  array{name: string, email: string, password: string, locale: string, roles: list<int>, email_verified: bool}  $data  Validated input.
     * @param  (Closure(AdminUser): void)|null  $alsoSave  Called with the saved user inside the transaction, after the roles are synced (the form's registered cards save there); whatever it throws rolls everything back as a `FormCardFailed`.
     *
     * @throws FormCardFailed when `$alsoSave` throws
     */
    public function handle(AdminUser $actor, array $data, ?Closure $alsoSave = null): AdminUser
    {
        Gate::forUser($actor)->authorize('create', Hata::userModel());

        return DB::transaction(function () use ($actor, $data, $alsoSave): AdminUser {
            $user = new (Hata::userModel())([
                'name' => $data['name'],
                'email' => Str::lower($data['email']),
                'password' => $data['password'],
                'locale' => $data['locale'],
            ]);
            $user->forceFill(['email_verified_at' => $data['email_verified'] ? now() : null])->save();

            $user = $this->syncUserRoles->handle($actor, $user, $data['roles']);

            if ($alsoSave !== null) {
                FormCardFailed::guard(fn () => $alsoSave($user));
            }

            return $user;
        });
    }
}

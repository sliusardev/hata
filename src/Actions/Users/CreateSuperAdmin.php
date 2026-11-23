<?php

namespace Hata\Actions\Users;

use Hata\Contracts\AdminUser;
use Hata\Enums\SystemRole;
use Hata\Hata;
use Hata\Models\Role;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Create a user with the super-admin role. Used by the admin:create command and the
 * DatabaseSeeder, where no authenticated user performs the action.
 */
class CreateSuperAdmin
{
    /**
     * @param  array{name: string, email: string, password: string}  $data  Validated input.
     */
    public function handle(array $data): AdminUser
    {
        return DB::transaction(function () use ($data): AdminUser {
            $role = Role::firstOrCreate(
                ['name' => SystemRole::SuperAdmin->value, 'guard_name' => 'web'],
                ['is_system' => true],
            );

            $user = new (Hata::userModel())([
                'name' => $data['name'],
                'email' => Str::lower($data['email']),
                'password' => $data['password'],
            ]);
            $user->forceFill(['email_verified_at' => now()])->save();

            $user->assignRole($role);

            return $user;
        });
    }
}

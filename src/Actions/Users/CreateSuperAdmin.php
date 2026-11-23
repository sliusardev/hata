<?php

namespace Hata\Actions\Users;

use App\Models\User;
use Hata\Enums\SystemRole;
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
    public function handle(array $data): User
    {
        return DB::transaction(function () use ($data): User {
            $role = Role::firstOrCreate(
                ['name' => SystemRole::SuperAdmin->value, 'guard_name' => 'web'],
                ['is_system' => true],
            );

            $user = new User([
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

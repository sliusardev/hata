<?php

namespace Hata\Actions\Fortify;

use Hata\Concerns\PasswordValidationRules;
use Hata\Concerns\ProfileValidationRules;
use Hata\Contracts\AdminUser;
use Hata\Hata;
use Hata\Models\Role;
use Illuminate\Support\Facades\Validator;
use Laravel\Fortify\Contracts\CreatesNewUsers;

class CreateNewUser implements CreatesNewUsers
{
    use PasswordValidationRules, ProfileValidationRules;

    /**
     * Validate and create a newly registered user with the default role from the site settings.
     *
     * @param  array<string, string>  $input
     */
    public function create(array $input): AdminUser
    {
        Validator::make($input, [
            ...$this->profileRules(),
            'password' => $this->passwordRules(),
        ])->validate();

        $user = new (Hata::userModel())([
            'name' => $input['name'],
            'email' => $input['email'],
            'password' => $input['password'],
        ]);
        $user->save();

        $this->assignDefaultRole($user);

        return $user;
    }

    /**
     * Give the role chosen in the site settings, never super-admin.
     */
    private function assignDefaultRole(AdminUser $user): void
    {
        $roleName = config('hata.admin.default_role');

        if (blank($roleName)) {
            return;
        }

        $role = Role::query()->where('name', $roleName)->first();

        if ($role !== null && ! $role->isSuperAdmin()) {
            $user->assignRole($role);
        }
    }
}

<?php

namespace App\Actions\Fortify;

use App\Concerns\PasswordValidationRules;
use App\Concerns\ProfileValidationRules;
use App\Models\Role;
use App\Models\User;
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
    public function create(array $input): User
    {
        Validator::make($input, [
            ...$this->profileRules(),
            'password' => $this->passwordRules(),
        ])->validate();

        $user = User::create([
            'name' => $input['name'],
            'email' => $input['email'],
            'password' => $input['password'],
        ]);

        $this->assignDefaultRole($user);

        return $user;
    }

    /**
     * Give the role chosen in the site settings, never super-admin.
     */
    private function assignDefaultRole(User $user): void
    {
        $roleName = config('admin.default_role');

        if (blank($roleName)) {
            return;
        }

        $role = Role::query()->where('name', $roleName)->first();

        if ($role !== null && ! $role->isSuperAdmin()) {
            $user->assignRole($role);
        }
    }
}

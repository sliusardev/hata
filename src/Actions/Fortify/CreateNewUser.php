<?php

namespace Hata\Actions\Fortify;

use Hata\Concerns\PasswordValidationRules;
use Hata\Concerns\ProfileValidationRules;
use Hata\Contracts\AdminUser;
use Hata\Exceptions\FormCardFailed;
use Hata\Hata;
use Hata\Models\Role;
use Hata\Support\Admin\Forms\FormCards;
use Hata\Support\Admin\Forms\FormContext;
use Hata\Support\Admin\Forms\FormExtensions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Contracts\CreatesNewUsers;

class CreateNewUser implements CreatesNewUsers
{
    use PasswordValidationRules, ProfileValidationRules;

    /**
     * Validate and create a newly registered user with the default role from the site settings. The registered form
     * cards are validated together with the user's own fields and saved in the same transaction.
     *
     * @param  array<string, mixed>  $input
     */
    public function create(array $input): AdminUser
    {
        $context = new FormContext('register', null, null, true);
        $cards = app(FormCards::class)->for($context);
        $extensions = app(FormExtensions::class);

        Validator::make(
            $input,
            [
                ...$this->profileRules(),
                'password' => $this->passwordRules(),
                ...$extensions->rules($cards, $context),
            ],
            [],
            $extensions->attributes($cards, $context),
        )->validate();

        try {
            return DB::transaction(function () use ($input, $cards, $extensions, $context): AdminUser {
                $user = new (Hata::userModel())([
                    'name' => $input['name'],
                    'email' => $input['email'],
                    'password' => $input['password'],
                ]);
                $user->save();

                $this->assignDefaultRole($user);

                $values = is_array($input['extensions'] ?? null) ? $input['extensions'] : [];
                $extensions->save($cards, $user, $values, $context);

                return $user;
            });
        } catch (FormCardFailed $failure) {
            if (! $failure->original() instanceof ValidationException) {
                throw $failure->original();
            }

            // Keyed as the card's view reads them, so they come back under its fields with the old input.
            throw ValidationException::withMessages($failure->errors());
        }
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

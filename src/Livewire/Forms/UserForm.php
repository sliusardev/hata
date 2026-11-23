<?php

namespace Hata\Livewire\Forms;

use Hata\Concerns\ProfileValidationRules;
use Hata\Contracts\AdminUser;
use Hata\Models\Role;
use Hata\Support\Localization\ContentLocales;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Livewire\Form;

class UserForm extends Form
{
    use ProfileValidationRules;

    public string $name = '';

    public string $email = '';

    public string $password = '';

    public string $password_confirmation = '';

    public string $locale = '';

    /**
     * @var array<int, int|string>
     */
    public array $roles = [];

    public bool $email_verified = false;

    public function fillFromUser(AdminUser $user): void
    {
        $this->name = $user->name;
        $this->email = $user->email;
        $this->locale = $user->locale ?? app(ContentLocales::class)->interfaceDefault();
        $this->roles = $user->roles->pluck('id')->map(fn (mixed $id): string => (string) $id)->values()->all();
        $this->email_verified = $user->email_verified_at !== null;
    }

    /**
     * @return array{name: string, email: string, password: string, locale: string, roles: list<int>, email_verified: bool}
     */
    public function validateForCreate(): array
    {
        /** @var array{name: string, email: string, password: string, locale: string, roles?: array<int, int|string>, email_verified: bool} $validated */
        $validated = $this->validate([
            ...$this->sharedRules(null),
            'password' => ['required', 'string', Password::default(), 'confirmed'],
            'email_verified' => ['boolean'],
        ]);

        return [
            'name' => $validated['name'],
            'email' => $validated['email'],
            'password' => $validated['password'],
            'locale' => $validated['locale'],
            'roles' => $this->roleIds($validated['roles'] ?? []),
            'email_verified' => $validated['email_verified'],
        ];
    }

    /**
     * Roles are left out when the actor is editing their own account (they cannot change them).
     *
     * @return array{name: string, email: string, locale: string, password: string|null, roles?: list<int>}
     */
    public function validateForUpdate(AdminUser $user, bool $includeRoles): array
    {
        /** @var array{name: string, email: string, password?: string|null, locale: string, roles?: array<int, int|string>} $validated */
        $validated = $this->validate([
            ...$this->sharedRules($user),
            'password' => ['nullable', 'string', Password::default(), 'confirmed'],
        ]);

        $data = [
            'name' => $validated['name'],
            'email' => $validated['email'],
            'locale' => $validated['locale'],
            'password' => filled($validated['password'] ?? null) ? $validated['password'] : null,
        ];

        if ($includeRoles) {
            $data['roles'] = $this->roleIds($validated['roles'] ?? []);
        }

        return $data;
    }

    /**
     * @return array<string, mixed>
     */
    private function sharedRules(?AdminUser $user): array
    {
        return [
            'name' => $this->nameRules(),
            'email' => $this->emailRules($user?->id),
            'locale' => ['required', 'string', Rule::in(array_keys(config('hata.admin.interface_locales')))],
            'roles' => ['array'],
            'roles.*' => ['integer', Rule::exists(Role::class, 'id')],
        ];
    }

    /**
     * @param  array<int, int|string>  $roles
     * @return list<int>
     */
    private function roleIds(array $roles): array
    {
        return array_values(array_unique(array_map('intval', $roles)));
    }
}

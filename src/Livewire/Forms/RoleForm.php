<?php

namespace Hata\Livewire\Forms;

use Hata\Models\Role;
use Hata\Support\Permissions\PermissionRegistry;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Form;

class RoleForm extends Form
{
    public string $label = '';

    public string $name = '';

    /**
     * Set once the technical name was typed by hand, so it stops following the label.
     */
    public bool $nameTouched = false;

    /**
     * @var array<int, string>
     */
    public array $permissions = [];

    public function fillFromRole(Role $role): void
    {
        $this->label = $role->label ?? '';
        $this->name = $role->name;
        $this->permissions = $role->permissions->pluck('name')->values()->all();
    }

    /**
     * Generate the technical name from the label until it is edited by hand.
     */
    public function syncNameFromLabel(): void
    {
        if (! $this->nameTouched) {
            $this->name = Str::slug($this->label);
        }
    }

    /**
     * @return array{label: string, name: string, permissions: list<string>}
     */
    public function validateForCreate(): array
    {
        /** @var array{label: string, name: string, permissions?: array<int, string>} $validated */
        $validated = $this->validate(
            [
                'label' => ['required', 'string', 'max:255'],
                'name' => ['required', 'string', 'max:255', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/', Rule::unique(Role::class, 'name')->where('guard_name', 'web')],
                ...$this->permissionRules(),
            ],
            attributes: $this->attributeNames(),
        );

        return [
            'label' => $validated['label'],
            'name' => $validated['name'],
            'permissions' => array_values($validated['permissions'] ?? []),
        ];
    }

    /**
     * @return array{label?: string, permissions: list<string>}
     */
    public function validateForUpdate(Role $role): array
    {
        /** @var array{label?: string, permissions?: array<int, string>} $validated */
        $validated = $this->validate(
            [
                ...($role->is_system ? [] : ['label' => ['required', 'string', 'max:255']]),
                ...$this->permissionRules(),
            ],
            attributes: $this->attributeNames(),
        );

        $data = ['permissions' => array_values($validated['permissions'] ?? [])];

        if (isset($validated['label'])) {
            $data['label'] = $validated['label'];
        }

        return $data;
    }

    /**
     * @return array<string, mixed>
     */
    private function permissionRules(): array
    {
        return [
            'permissions' => ['array'],
            'permissions.*' => ['string', Rule::in(app(PermissionRegistry::class)->all())],
        ];
    }

    /**
     * @return array<string, string>
     */
    private function attributeNames(): array
    {
        return [
            'label' => __('roles.form.label'),
            'name' => __('roles.form.name'),
            'permissions' => __('roles.form.permissions'),
        ];
    }
}

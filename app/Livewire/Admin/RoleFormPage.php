<?php

namespace App\Livewire\Admin;

use App\Livewire\Forms\RoleForm;
use App\Support\Permissions\AccessRules;
use App\Support\Permissions\PermissionRegistry;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Component;

/**
 * Base for the role create and edit pages: the role form and its permission matrix (groups
 * from the registry, which permissions the current user may grant under rule 5, and
 * per-group select all / clear all).
 */
abstract class RoleFormPage extends Component
{
    public RoleForm $form;

    /**
     * @return array<string, list<array{name: string, grantable: bool}>>
     */
    #[Computed]
    public function permissionGroups(): array
    {
        $rules = app(AccessRules::class);
        $actor = Auth::user();

        return array_map(
            fn (array $permissions): array => array_map(
                fn (string $permission): array => ['name' => $permission, 'grantable' => $rules->canGrantPermission($actor, $permission)],
                $permissions,
            ),
            app(PermissionRegistry::class)->groups(),
        );
    }

    /**
     * Check every permission of the group that the current user may grant.
     */
    public function selectGroup(string $group): void
    {
        $this->form->permissions = array_values(array_unique([
            ...$this->form->permissions,
            ...$this->grantableIn($group),
        ]));
    }

    /**
     * Uncheck every permission of the group that the current user may grant.
     */
    public function clearGroup(string $group): void
    {
        $this->form->permissions = array_values(array_diff($this->form->permissions, $this->grantableIn($group)));
    }

    /**
     * @return list<string>
     */
    private function grantableIn(string $group): array
    {
        return array_values(array_map(
            fn (array $permission): string => $permission['name'],
            array_filter($this->permissionGroups[$group] ?? [], fn (array $permission): bool => $permission['grantable']),
        ));
    }
}

<?php

use Hata\Actions\Roles\DeleteRole;
use Hata\Exceptions\ProtectedActionException;
use Hata\Models\Role;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('roles.index.title')] class extends Component {
    #[Locked]
    public ?int $deletingRoleId = null;

    public bool $showDeleteModal = false;

    public function mount(): void
    {
        $this->authorize('viewAny', Role::class);
    }

    /**
     * @return Collection<int, Role>
     */
    #[Computed]
    public function roles(): Collection
    {
        return Role::query()
            ->withCount(['users', 'permissions'])
            ->orderByDesc('is_system')
            ->orderBy('name')
            ->get();
    }

    /**
     * Custom roles can be deleted. Roles in use still open the dialog, which explains why not.
     */
    public function canDelete(Role $role): bool
    {
        return Auth::user()->can('delete', $role) && ! $role->is_system;
    }

    #[Computed]
    public function deletingRole(): ?Role
    {
        return $this->deletingRoleId ? Role::withCount('users')->find($this->deletingRoleId) : null;
    }

    public function confirmDelete(int $roleId): void
    {
        $role = Role::findOrFail($roleId);

        $this->authorize('delete', $role);

        $this->deletingRoleId = $role->id;
        $this->showDeleteModal = true;
    }

    public function deleteRole(DeleteRole $deleteRole): void
    {
        $role = Role::findOrFail($this->deletingRoleId);

        $this->authorize('delete', $role);

        try {
            $deleteRole->handle(Auth::user(), $role);

            Flux::toast(variant: 'success', text: __('roles.messages.deleted'));
        } catch (ProtectedActionException $exception) {
            Flux::toast(variant: 'danger', text: $exception->getMessage());
        }

        $this->showDeleteModal = false;
        $this->deletingRoleId = null;
        unset($this->roles);
    }
}; ?>

<section class="w-full space-y-6">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <flux:heading size="xl" level="1">{{ __('roles.index.title') }}</flux:heading>
            <flux:subheading>{{ __('roles.index.description') }}</flux:subheading>
        </div>

        @can('create', Hata\Models\Role::class)
            <flux:button variant="primary" icon="plus" :href="route('admin.roles.create')" wire:navigate>
                {{ __('roles.index.create') }}
            </flux:button>
        @endcan
    </div>

    @if ($this->roles->isEmpty())
        <x-admin.empty-state icon="shield-check" :heading="__('roles.index.empty.title')" :description="__('roles.index.empty.description')" />
    @else
        <flux:table>
            <flux:table.columns>
                <flux:table.column>{{ __('roles.index.columns.name') }}</flux:table.column>
                <flux:table.column class="max-md:hidden">{{ __('roles.index.columns.technical_name') }}</flux:table.column>
                <flux:table.column align="end">{{ __('roles.index.columns.users') }}</flux:table.column>
                <flux:table.column align="end" class="max-sm:hidden">{{ __('roles.index.columns.permissions') }}</flux:table.column>
                <flux:table.column align="end"><span class="sr-only">{{ __('roles.index.columns.actions') }}</span></flux:table.column>
            </flux:table.columns>

            <flux:table.rows>
                @foreach ($this->roles as $role)
                    <flux:table.row :key="$role->id">
                        <flux:table.cell>
                            <div class="flex items-center gap-2 font-medium text-zinc-800 dark:text-white">
                                @can('view', $role)
                                    <a href="{{ route('admin.roles.edit', $role) }}" wire:navigate class="hover:underline" data-test="edit-role-link-{{ $role->id }}">{{ $role->displayName() }}</a>
                                @else
                                    <span>{{ $role->displayName() }}</span>
                                @endcan
                                @if ($role->is_system)
                                    <flux:badge size="sm" color="zinc">{{ __('roles.system') }}</flux:badge>
                                @endif
                            </div>
                        </flux:table.cell>
                        <flux:table.cell class="max-md:hidden"><code class="text-xs">{{ $role->name }}</code></flux:table.cell>
                        <flux:table.cell align="end">{{ $role->users_count }}</flux:table.cell>
                        <flux:table.cell align="end" class="max-sm:hidden">
                            {{ $role->isSuperAdmin() ? __('roles.all_permissions') : $role->permissions_count }}
                        </flux:table.cell>
                        <flux:table.cell align="end">
                            <x-admin.row-actions data-test="role-actions-{{ $role->id }}">
                                @can('view', $role)
                                    @if (auth()->user()->can('update', $role) && ! $role->isSuperAdmin())
                                        <flux:menu.item icon="pencil-square" :href="route('admin.roles.edit', $role)" wire:navigate>{{ __('common.actions.edit') }}</flux:menu.item>
                                    @else
                                        <flux:menu.item icon="eye" :href="route('admin.roles.edit', $role)" wire:navigate>{{ __('common.actions.view') }}</flux:menu.item>
                                    @endif
                                @endcan
                                @if ($this->canDelete($role))
                                    <flux:menu.item variant="danger" icon="trash" wire:click="confirmDelete({{ $role->id }})" data-test="delete-role-{{ $role->id }}">{{ __('common.actions.delete') }}</flux:menu.item>
                                @endif
                            </x-admin.row-actions>
                        </flux:table.cell>
                    </flux:table.row>
                @endforeach
            </flux:table.rows>
        </flux:table>
    @endif

    @php($deletingRole = $this->deletingRole)
    <x-confirm-modal
        wire:model.self="showDeleteModal"
        :heading="__('roles.delete.title')"
        :confirm-label="__('roles.delete.submit')"
        confirm-action="deleteRole"
        :confirmable="($deletingRole?->users_count ?? 0) === 0"
    >
        @if (($deletingRole?->users_count ?? 0) > 0)
            {{ __('roles.delete.in_use', ['name' => $deletingRole->displayName(), 'users' => trans_choice('users.count', $deletingRole->users_count)]) }}
        @else
            {{ __('roles.delete.confirm', ['name' => $deletingRole?->displayName()]) }}
        @endif
    </x-confirm-modal>
</section>

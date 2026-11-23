<?php

use Hata\Actions\Roles\DeleteRole;
use Hata\Actions\Roles\UpdateRole;
use Hata\Exceptions\ProtectedActionException;
use Hata\Livewire\Admin\RoleFormPage;
use Hata\Models\Role;
use Hata\Support\Toast;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;

new #[Layout('hata::layouts.app')] #[Title('hata::roles.edit.title')] class extends RoleFormPage {
    #[Locked]
    public Role $role;

    public bool $showDeleteModal = false;

    /**
     * Opens in view mode for users with roles.view but without roles.edit.
     */
    public function mount(Role $role): void
    {
        $this->authorize('view', $role);

        $this->role = $role->load('permissions')->loadCount('users');
        $this->form->fillFromRole($this->role);
    }

    #[Computed]
    public function readOnly(): bool
    {
        return $this->role->isSuperAdmin() || ! Auth::user()->can('update', $this->role);
    }

    #[Computed]
    public function canDelete(): bool
    {
        return Auth::user()->can('delete', $this->role) && ! $this->role->is_system;
    }

    public function save(UpdateRole $updateRole): void
    {
        $this->authorize('update', $this->role);

        $data = $this->form->validateForUpdate($this->role);

        try {
            $updateRole->handle(Auth::user(), $this->role, $data);
        } catch (ProtectedActionException $exception) {
            $this->addError('form.permissions', $exception->getMessage());

            return;
        }

        Toast::success(__('hata::roles.messages.updated'));

        $this->redirectRoute('admin.roles.index', navigate: true);
    }

    public function deleteRole(DeleteRole $deleteRole): void
    {
        $this->authorize('delete', $this->role);

        try {
            $deleteRole->handle(Auth::user(), $this->role);
        } catch (ProtectedActionException $exception) {
            $this->showDeleteModal = false;
            Flux::toast(variant: 'danger', text: $exception->getMessage());

            return;
        }

        Toast::success(__('hata::roles.messages.deleted'));

        $this->redirectRoute('admin.roles.index', navigate: true);
    }
}; ?>

<section class="w-full max-w-3xl space-y-6">
    <div>
        <flux:button variant="ghost" size="sm" icon="arrow-left" :href="route('admin.roles.index')" wire:navigate class="-ms-2 mb-2">
            {{ __('hata::roles.index.title') }}
        </flux:button>
        <div class="flex flex-wrap items-center gap-2">
            <flux:heading size="xl" level="1">{{ $this->readOnly ? __('hata::roles.edit.view_title') : __('hata::roles.edit.title') }}</flux:heading>
            @if ($role->is_system)
                <flux:badge size="sm" color="zinc">{{ __('hata::roles.system') }}</flux:badge>
            @endif
        </div>
        <flux:subheading>{{ $role->displayName() }} · <code>{{ $role->name }}</code></flux:subheading>
    </div>

    @if ($role->isSuperAdmin())
        <flux:callout icon="shield-check" variant="secondary" :heading="__('hata::roles.form.super_admin_heading')" :text="__('hata::roles.form.super_admin_description')" />
    @else
        @if ($this->readOnly)
            <flux:callout icon="eye" variant="secondary" :heading="__('hata::roles.edit.read_only')" />
        @endif

        <form wire:submit="save" class="space-y-6">
            <flux:fieldset class="space-y-6">
                <flux:legend>{{ __('hata::roles.form.details') }}</flux:legend>

                @if ($role->is_system)
                    <flux:text>{{ __('hata::roles.form.system_name') }}</flux:text>
                @else
                    <div class="grid gap-6 sm:grid-cols-2">
                        <flux:input wire:model="form.label" :label="__('hata::roles.form.label')" required :disabled="$this->readOnly" autocomplete="off" />
                        <flux:input :value="$role->name" :label="__('hata::roles.form.name')" disabled class="font-mono" />
                    </div>
                @endif
            </flux:fieldset>

            <flux:separator variant="subtle" />

            <flux:fieldset>
                <flux:legend>{{ __('hata::roles.form.permissions') }}</flux:legend>
                <flux:description class="mb-4">{{ __('hata::roles.form.permissions_description') }}</flux:description>

                @include('hata::partials.admin.permission-matrix', ['permissionGroups' => $this->permissionGroups, 'readOnly' => $this->readOnly])
            </flux:fieldset>

            @unless ($this->readOnly)
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <div>
                        @if ($this->canDelete)
                            <flux:button variant="danger" icon="trash" wire:click="$set('showDeleteModal', true)" data-test="delete-role-button">
                                {{ __('hata::roles.delete.submit') }}
                            </flux:button>
                        @endif
                    </div>

                    <div class="flex gap-3">
                        <flux:button variant="ghost" :href="route('admin.roles.index')" wire:navigate>{{ __('hata::common.actions.cancel') }}</flux:button>
                        <flux:button variant="primary" type="submit" data-test="save-role-button">{{ __('hata::roles.edit.submit') }}</flux:button>
                    </div>
                </div>
            @endunless
        </form>
    @endif

    @if ($this->canDelete)
        <x-hata::confirm-modal
            wire:model.self="showDeleteModal"
            :heading="__('hata::roles.delete.title')"
            :confirm-label="__('hata::roles.delete.submit')"
            confirm-action="deleteRole"
            :confirmable="$role->users_count === 0"
        >
            @if ($role->users_count > 0)
                {{ __('hata::roles.delete.in_use', ['name' => $role->displayName(), 'users' => trans_choice('hata::users.count', $role->users_count)]) }}
            @else
                {{ __('hata::roles.delete.confirm', ['name' => $role->displayName()]) }}
            @endif
        </x-hata::confirm-modal>
    @endif
</section>

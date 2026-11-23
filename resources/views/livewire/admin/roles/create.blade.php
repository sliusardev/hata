<?php

use Hata\Actions\Roles\CreateRole;
use Hata\Exceptions\ProtectedActionException;
use Hata\Livewire\Admin\RoleFormPage;
use Hata\Models\Role;
use Hata\Support\Toast;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;

new #[Layout('hata::layouts.app')] #[Title('hata::roles.create.title')] class extends RoleFormPage {
    public function mount(): void
    {
        $this->authorize('create', Role::class);
    }

    public function updatedFormLabel(): void
    {
        $this->form->syncNameFromLabel();
    }

    public function updatedFormName(): void
    {
        $this->form->nameTouched = $this->form->name !== '';
    }

    public function save(CreateRole $createRole): void
    {
        $this->authorize('create', Role::class);

        $data = $this->form->validateForCreate();

        try {
            $createRole->handle(Auth::user(), $data);
        } catch (ProtectedActionException $exception) {
            $this->addError('form.permissions', $exception->getMessage());

            return;
        }

        Toast::success(__('hata::roles.messages.created'));

        $this->redirectRoute('admin.roles.index', navigate: true);
    }
}; ?>

<section class="w-full max-w-3xl space-y-6">
    <div>
        <flux:button variant="ghost" size="sm" icon="arrow-left" :href="route('admin.roles.index')" wire:navigate class="-ms-2 mb-2">
            {{ __('hata::roles.index.title') }}
        </flux:button>
        <flux:heading size="xl" level="1">{{ __('hata::roles.create.title') }}</flux:heading>
        <flux:subheading>{{ __('hata::roles.create.description') }}</flux:subheading>
    </div>

    <form wire:submit="save" class="space-y-6">
        <flux:fieldset class="space-y-6">
            <flux:legend>{{ __('hata::roles.form.details') }}</flux:legend>

            <div class="grid gap-6 sm:grid-cols-2">
                <flux:input wire:model.live.debounce.300ms="form.label" :label="__('hata::roles.form.label')" required autocomplete="off" />
                <flux:input wire:model.blur="form.name" :label="__('hata::roles.form.name')" :description:trailing="__('hata::roles.form.name_description')" required autocomplete="off" class="font-mono" />
            </div>
        </flux:fieldset>

        <flux:separator variant="subtle" />

        <flux:fieldset>
            <flux:legend>{{ __('hata::roles.form.permissions') }}</flux:legend>
            <flux:description class="mb-4">{{ __('hata::roles.form.permissions_description') }}</flux:description>

            @include('hata::partials.admin.permission-matrix', ['permissionGroups' => $this->permissionGroups, 'readOnly' => false])
        </flux:fieldset>

        <div class="flex justify-end gap-3">
            <flux:button variant="ghost" :href="route('admin.roles.index')" wire:navigate>{{ __('hata::common.actions.cancel') }}</flux:button>
            <flux:button variant="primary" type="submit" data-test="save-role-button">{{ __('hata::roles.create.submit') }}</flux:button>
        </div>
    </form>
</section>

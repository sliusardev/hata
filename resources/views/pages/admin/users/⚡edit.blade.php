<?php

use Hata\Actions\Users\DeleteUser;
use Hata\Actions\Users\UpdateUser;
use Hata\Exceptions\ProtectedActionException;
use Hata\Livewire\Forms\UserForm;
use Hata\Models\Role;
use App\Models\User;
use Hata\Support\Permissions\AccessRules;
use Hata\Support\Toast;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('users.edit.title')] class extends Component {
    #[Locked]
    public User $user;

    public UserForm $form;

    public bool $showDeleteModal = false;

    public function mount(User $user, AccessRules $rules): void
    {
        $this->authorize('update', $user);

        abort_if($rules->userUpdateDenial(Auth::user(), $user) !== null, 403);

        $this->user = $user->load('roles');
        $this->form->fillFromUser($this->user);
    }

    #[Computed]
    public function rolesLocked(): bool
    {
        return Auth::user()->is($this->user);
    }

    #[Computed]
    public function canDelete(): bool
    {
        return Auth::user()->can('delete', $this->user)
            && app(AccessRules::class)->userDeletionDenial(Auth::user(), $this->user) === null;
    }

    /**
     * @return list<array{role: Role, assignable: bool}>
     */
    #[Computed]
    public function roleOptions(): array
    {
        return Role::with('permissions')->orderByDesc('is_system')->orderBy('name')->get()
            ->map(fn (Role $role): array => ['role' => $role, 'assignable' => Auth::user()->can('assign', $role)])
            ->values()
            ->all();
    }

    public function save(UpdateUser $updateUser): void
    {
        $this->authorize('update', $this->user);

        $data = $this->form->validateForUpdate($this->user, includeRoles: ! $this->rolesLocked);

        try {
            $updateUser->handle(Auth::user(), $this->user, $data);
        } catch (ProtectedActionException $exception) {
            $this->addError('form.roles', $exception->getMessage());

            return;
        }

        Toast::success(__('users.messages.updated'));

        $this->redirectRoute('admin.users.index', navigate: true);
    }

    public function deleteUser(DeleteUser $deleteUser): void
    {
        $this->authorize('delete', $this->user);

        try {
            $deleteUser->handle(Auth::user(), $this->user);
        } catch (ProtectedActionException $exception) {
            $this->showDeleteModal = false;
            Flux::toast(variant: 'danger', text: $exception->getMessage());

            return;
        }

        Toast::success(__('users.messages.deleted'));

        $this->redirectRoute('admin.users.index', navigate: true);
    }
}; ?>

<section class="w-full max-w-2xl space-y-6">
    <div>
        <flux:button variant="ghost" size="sm" icon="arrow-left" :href="route('admin.users.index')" wire:navigate class="-ms-2 mb-2">
            {{ __('users.index.title') }}
        </flux:button>
        <flux:heading size="xl" level="1">{{ __('users.edit.title') }}</flux:heading>
        <flux:subheading>{{ $user->name }} · {{ $user->email }}</flux:subheading>
    </div>

    <form wire:submit="save" class="space-y-6">
        @include('partials.admin.user-form', [
            'mode' => 'edit',
            'roleOptions' => $this->roleOptions,
            'rolesLocked' => $this->rolesLocked,
            'editedUser' => $user,
        ])

        <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
                @if ($this->canDelete)
                    <flux:button variant="danger" icon="trash" wire:click="$set('showDeleteModal', true)" data-test="delete-user-button">
                        {{ __('users.delete.submit') }}
                    </flux:button>
                @endif
            </div>

            <div class="flex gap-3">
                <flux:button variant="ghost" :href="route('admin.users.index')" wire:navigate>{{ __('common.actions.cancel') }}</flux:button>
                <flux:button variant="primary" type="submit" data-test="save-user-button">{{ __('users.edit.submit') }}</flux:button>
            </div>
        </div>
    </form>

    @if ($this->canDelete)
        <x-confirm-modal
            wire:model.self="showDeleteModal"
            :heading="__('users.delete.title')"
            :confirm-label="__('users.delete.submit')"
            confirm-action="deleteUser"
        >
            {{ __('users.delete.confirm', ['name' => $user->name]) }}
        </x-confirm-modal>
    @endif
</section>

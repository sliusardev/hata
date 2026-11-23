<?php

use Hata\Actions\Users\DeleteUser;
use Hata\Actions\Users\UpdateUser;
use Hata\Exceptions\ProtectedActionException;
use Hata\Livewire\Forms\UserForm;
use Hata\Models\Role;
use Hata\Contracts\AdminUser;
use Hata\Hata;
use Hata\Support\Permissions\AccessRules;
use Hata\Support\Toast;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Layout('hata::layouts.app')] #[Title('hata::users.edit.title')] class extends Component {
    /**
     * Untyped on purpose: Livewire resolves a typed public property named like a route parameter from the container,
     * and an interface cannot be built. `mount()` sets the host's model.
     *
     * @var AdminUser
     */
    #[Locked]
    public $user;

    public UserForm $form;

    public bool $showDeleteModal = false;

    /**
     * From the route the user comes as its raw key (a test may pass the model): Livewire binds a route model only to
     * a class type (an interface or a union is resolved from the container instead), and the user's class is the
     * host's (`Hata::userModel()`).
     */
    public function mount(mixed $user, AccessRules $rules): void
    {
        if (! $user instanceof AdminUser) {
            $model = Hata::userModel();
            $user = (new $model)->resolveRouteBinding($user) ?? abort(404);
        }

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

        Toast::success(__('hata::users.messages.updated'));

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

        Toast::success(__('hata::users.messages.deleted'));

        $this->redirectRoute('admin.users.index', navigate: true);
    }
}; ?>

<section class="w-full max-w-2xl space-y-6">
    <div>
        <flux:button variant="ghost" size="sm" icon="arrow-left" :href="route('admin.users.index')" wire:navigate class="-ms-2 mb-2">
            {{ __('hata::users.index.title') }}
        </flux:button>
        <flux:heading size="xl" level="1">{{ __('hata::users.edit.title') }}</flux:heading>
        <flux:subheading>{{ $user->name }} · {{ $user->email }}</flux:subheading>
    </div>

    <form wire:submit="save" class="space-y-6">
        @include('hata::partials.admin.user-form', [
            'mode' => 'edit',
            'roleOptions' => $this->roleOptions,
            'rolesLocked' => $this->rolesLocked,
            'editedUser' => $user,
        ])

        <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
                @if ($this->canDelete)
                    <flux:button variant="danger" icon="trash" wire:click="$set('showDeleteModal', true)" data-test="delete-user-button">
                        {{ __('hata::users.delete.submit') }}
                    </flux:button>
                @endif
            </div>

            <div class="flex gap-3">
                <flux:button variant="ghost" :href="route('admin.users.index')" wire:navigate>{{ __('hata::common.actions.cancel') }}</flux:button>
                <flux:button variant="primary" type="submit" data-test="save-user-button">{{ __('hata::users.edit.submit') }}</flux:button>
            </div>
        </div>
    </form>

    @if ($this->canDelete)
        <x-hata::confirm-modal
            wire:model.self="showDeleteModal"
            :heading="__('hata::users.delete.title')"
            :confirm-label="__('hata::users.delete.submit')"
            confirm-action="deleteUser"
        >
            {{ __('hata::users.delete.confirm', ['name' => $user->name]) }}
        </x-hata::confirm-modal>
    @endif
</section>

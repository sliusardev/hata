<?php

use App\Actions\Users\CreateUser;
use App\Exceptions\ProtectedActionException;
use App\Livewire\Forms\UserForm;
use App\Models\Role;
use App\Models\User;
use App\Support\Toast;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('users.create.title')] class extends Component {
    public UserForm $form;

    public function mount(): void
    {
        $this->authorize('create', User::class);

        $this->form->locale = config('app.locale');
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

    public function save(CreateUser $createUser): void
    {
        $this->authorize('create', User::class);

        $data = $this->form->validateForCreate();

        try {
            $createUser->handle(Auth::user(), $data);
        } catch (ProtectedActionException $exception) {
            $this->addError('form.roles', $exception->getMessage());

            return;
        }

        Toast::success(__('users.messages.created'));

        $this->redirectRoute('admin.users.index', navigate: true);
    }
}; ?>

<section class="w-full max-w-2xl space-y-6">
    <div>
        <flux:button variant="ghost" size="sm" icon="arrow-left" :href="route('admin.users.index')" wire:navigate class="-ms-2 mb-2">
            {{ __('users.index.title') }}
        </flux:button>
        <flux:heading size="xl" level="1">{{ __('users.create.title') }}</flux:heading>
        <flux:subheading>{{ __('users.create.description') }}</flux:subheading>
    </div>

    <form wire:submit="save" class="space-y-6">
        @include('partials.admin.user-form', [
            'mode' => 'create',
            'roleOptions' => $this->roleOptions,
            'rolesLocked' => false,
            'editedUser' => null,
        ])

        <div class="flex justify-end gap-3">
            <flux:button variant="ghost" :href="route('admin.users.index')" wire:navigate>{{ __('common.actions.cancel') }}</flux:button>
            <flux:button variant="primary" type="submit" data-test="save-user-button">{{ __('users.create.submit') }}</flux:button>
        </div>
    </form>
</section>

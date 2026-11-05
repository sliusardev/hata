<?php

use App\Actions\Users\DeleteUser;
use App\Exceptions\ProtectedActionException;
use App\Models\Role;
use App\Models\User;
use App\Support\Permissions\AccessRules;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('users.index.title')] class extends Component {
    use WithPagination;

    private const array SORTABLE = ['name', 'email', 'created_at'];

    #[Url(except: '')]
    public string $search = '';

    #[Url(except: '')]
    public string $role = '';

    #[Url(except: 'created_at')]
    public string $sortBy = 'created_at';

    #[Url(except: 'desc')]
    public string $sortDirection = 'desc';

    #[Locked]
    public ?int $deletingUserId = null;

    public bool $showDeleteModal = false;

    public function mount(): void
    {
        $this->authorize('viewAny', User::class);
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedRole(): void
    {
        $this->resetPage();
    }

    public function sort(string $column): void
    {
        if (! in_array($column, self::SORTABLE, true)) {
            return;
        }

        if ($this->sortBy === $column) {
            $this->sortDirection = $this->sortDirection === 'asc' ? 'desc' : 'asc';
        } else {
            $this->sortBy = $column;
            $this->sortDirection = 'asc';
        }

        $this->resetPage();
    }

    /**
     * @return LengthAwarePaginator<int, User>
     */
    #[Computed]
    public function users(): LengthAwarePaginator
    {
        $sortBy = in_array($this->sortBy, self::SORTABLE, true) ? $this->sortBy : 'created_at';
        $direction = $this->sortDirection === 'asc' ? 'asc' : 'desc';

        return User::query()
            ->with('roles')
            ->search($this->search)
            ->when($this->role !== '', fn ($query) => $query->whereHas('roles', fn ($roles) => $roles->whereKey((int) $this->role)))
            ->orderBy($sortBy, $direction)
            ->orderBy('id', $direction)
            ->paginate(25);
    }

    /**
     * @return Collection<int, Role>
     */
    #[Computed]
    public function roles(): Collection
    {
        return Role::query()->orderByDesc('is_system')->orderBy('name')->get();
    }

    #[Computed]
    public function hasAnyUsers(): bool
    {
        return User::query()->exists();
    }

    public function canEdit(User $user): bool
    {
        return Auth::user()->can('update', $user)
            && app(AccessRules::class)->userUpdateDenial(Auth::user(), $user) === null;
    }

    public function canDelete(User $user): bool
    {
        return Auth::user()->can('delete', $user)
            && app(AccessRules::class)->userDeletionDenial(Auth::user(), $user) === null;
    }

    #[Computed]
    public function deletingUser(): ?User
    {
        return $this->deletingUserId ? User::find($this->deletingUserId) : null;
    }

    public function confirmDelete(int $userId): void
    {
        $user = User::findOrFail($userId);

        $this->authorize('delete', $user);

        $this->deletingUserId = $user->id;
        $this->showDeleteModal = true;
    }

    public function deleteUser(DeleteUser $deleteUser): void
    {
        $user = User::findOrFail($this->deletingUserId);

        $this->authorize('delete', $user);

        try {
            $deleteUser->handle(Auth::user(), $user);

            Flux::toast(variant: 'success', text: __('users.messages.deleted'));
        } catch (ProtectedActionException $exception) {
            Flux::toast(variant: 'danger', text: $exception->getMessage());
        }

        $this->showDeleteModal = false;
        $this->deletingUserId = null;
        unset($this->users, $this->hasAnyUsers);
    }
}; ?>

<section class="w-full space-y-6">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <flux:heading size="xl" level="1">{{ __('users.index.title') }}</flux:heading>
            <flux:subheading>{{ __('users.index.description') }}</flux:subheading>
        </div>

        @can('create', App\Models\User::class)
            <flux:button variant="primary" icon="plus" :href="route('admin.users.create')" wire:navigate>
                {{ __('users.index.create') }}
            </flux:button>
        @endcan
    </div>

    <div class="flex flex-col gap-3 sm:flex-row">
        <flux:input
            wire:model.live.debounce.300ms="search"
            icon="magnifying-glass"
            :placeholder="__('users.index.search')"
            :aria-label="__('users.index.search')"
            clearable
            class="sm:max-w-sm"
        />

        <flux:select wire:model.live="role" :aria-label="__('users.index.filter_by_role')" class="sm:max-w-56">
            <flux:select.option value="">{{ __('users.index.all_roles') }}</flux:select.option>
            @foreach ($this->roles as $roleOption)
                <flux:select.option :value="$roleOption->id" wire:key="role-filter-{{ $roleOption->id }}">{{ $roleOption->displayName() }}</flux:select.option>
            @endforeach
        </flux:select>
    </div>

    @if ($this->users->isEmpty())
        <x-admin.empty-state
            icon="users"
            :heading="$this->hasAnyUsers ? __('users.index.no_results.title') : __('users.index.empty.title')"
            :description="$this->hasAnyUsers ? __('users.index.no_results.description') : __('users.index.empty.description')"
        />
    @else
        <flux:table :paginate="$this->users">
            <flux:table.columns>
                <flux:table.column sortable :sorted="$sortBy === 'name'" :direction="$sortDirection" wire:click="sort('name')">{{ __('users.index.columns.user') }}</flux:table.column>
                <flux:table.column sortable :sorted="$sortBy === 'email'" :direction="$sortDirection" wire:click="sort('email')" class="max-md:hidden">{{ __('users.index.columns.email') }}</flux:table.column>
                <flux:table.column>{{ __('users.index.columns.roles') }}</flux:table.column>
                <flux:table.column class="max-lg:hidden">{{ __('users.index.columns.locale') }}</flux:table.column>
                <flux:table.column sortable :sorted="$sortBy === 'created_at'" :direction="$sortDirection" wire:click="sort('created_at')" class="max-lg:hidden">{{ __('users.index.columns.created_at') }}</flux:table.column>
                <flux:table.column align="end"><span class="sr-only">{{ __('users.index.columns.actions') }}</span></flux:table.column>
            </flux:table.columns>

            <flux:table.rows>
                @foreach ($this->users as $user)
                    <flux:table.row :key="$user->id">
                        <flux:table.cell>
                            <div class="flex items-center gap-3">
                                <flux:avatar size="sm" :name="$user->name" :initials="$user->initials()" />
                                <div class="min-w-0">
                                    <div class="flex items-center gap-2 font-medium text-zinc-800 dark:text-white">
                                        <span class="truncate">{{ $user->name }}</span>
                                        @if ($user->is(auth()->user()))
                                            <flux:badge size="sm" color="zinc">{{ __('users.index.you') }}</flux:badge>
                                        @endif
                                    </div>
                                    <div class="truncate text-xs text-zinc-500 md:hidden dark:text-zinc-400">{{ $user->email }}</div>
                                </div>
                            </div>
                        </flux:table.cell>
                        <flux:table.cell class="max-md:hidden">{{ $user->email }}</flux:table.cell>
                        <flux:table.cell>
                            <div class="flex flex-wrap gap-1">
                                @forelse ($user->roles as $userRole)
                                    <flux:badge size="sm" :color="$userRole->isSuperAdmin() ? 'amber' : 'zinc'">{{ $userRole->displayName() }}</flux:badge>
                                @empty
                                    <flux:text size="sm" variant="subtle">{{ __('users.index.no_roles') }}</flux:text>
                                @endforelse
                            </div>
                        </flux:table.cell>
                        <flux:table.cell class="max-lg:hidden">{{ config('app.available_locales')[$user->locale ?? config('app.locale')] ?? '—' }}</flux:table.cell>
                        <flux:table.cell class="max-lg:hidden">{{ $user->created_at?->isoFormat('LL') }}</flux:table.cell>
                        <flux:table.cell align="end">
                            <div class="flex justify-end gap-1">
                                @if ($this->canEdit($user))
                                    <flux:button size="sm" variant="ghost" icon="pencil-square" :href="route('admin.users.edit', $user)" wire:navigate :aria-label="__('common.actions.edit')" :tooltip="__('common.actions.edit')" />
                                @endif
                                @if ($this->canDelete($user))
                                    <flux:button size="sm" variant="ghost" icon="trash" wire:click="confirmDelete({{ $user->id }})" :aria-label="__('common.actions.delete')" :tooltip="__('common.actions.delete')" data-test="delete-user-{{ $user->id }}" />
                                @endif
                            </div>
                        </flux:table.cell>
                    </flux:table.row>
                @endforeach
            </flux:table.rows>
        </flux:table>
    @endif

    <x-confirm-modal
        wire:model.self="showDeleteModal"
        :heading="__('users.delete.title')"
        :confirm-label="__('users.delete.submit')"
        confirm-action="deleteUser"
    >
        {{ __('users.delete.confirm', ['name' => $this->deletingUser?->name]) }}
    </x-confirm-modal>
</section>

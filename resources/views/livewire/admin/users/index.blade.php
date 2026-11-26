<?php

use Hata\Actions\Users\DeleteUser;
use Hata\Exceptions\ProtectedActionException;
use Hata\Models\Role;
use Hata\Contracts\AdminUser;
use Hata\Hata;
use Hata\Livewire\Admin\Concerns\HasListExtensions;
use Hata\Support\Admin\Lists\ListContext;
use Hata\Support\Permissions\AccessRules;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Layout('hata::layouts.app')] #[Title('hata::users.index.title')] class extends Component {
    use HasListExtensions;
    use WithPagination;

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
        $this->authorize('viewAny', Hata::userModel());
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedRole(): void
    {
        $this->resetPage();
    }

    protected function listContext(): ListContext
    {
        return new ListContext('users', null);
    }

    /**
     * The keys the list may be sorted by: the name and the visible sortable columns.
     *
     * @return list<string>
     */
    private function sortableKeys(): array
    {
        return ['name', ...array_map(
            fn ($column): string => $column->key,
            array_filter($this->listColumns, fn ($column): bool => $column->sort !== null),
        )];
    }

    public function sort(string $column): void
    {
        if (! in_array($column, $this->sortableKeys(), true)) {
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
     * @return LengthAwarePaginator<int, AdminUser>
     */
    #[Computed]
    public function users(): LengthAwarePaginator
    {
        $sortBy = in_array($this->sortBy, $this->sortableKeys(), true) ? $this->sortBy : 'created_at';
        $direction = $this->sortDirection === 'asc' ? 'asc' : 'desc';

        $query = $this->applyColumnLoads(Hata::userModel()::query())
            ->search($this->search)
            ->when($this->role !== '', fn ($query) => $query->whereHas('roles', fn ($roles) => $roles->whereKey((int) $this->role)));

        match (true) {
            $sortBy === 'name' => $query->orderBy('name', $direction),
            $this->registeredSort($sortBy) !== null => ($this->registeredSort($sortBy))($query, $direction),
            default => $query->orderBy('created_at', $direction),
        };

        return $query->orderBy('id', $direction)->paginate(25);
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
        return Hata::userModel()::query()->exists();
    }

    public function canEdit(AdminUser $user): bool
    {
        return Auth::user()->can('update', $user)
            && app(AccessRules::class)->userUpdateDenial(Auth::user(), $user) === null;
    }

    public function canDelete(AdminUser $user): bool
    {
        return Auth::user()->can('delete', $user)
            && app(AccessRules::class)->userDeletionDenial(Auth::user(), $user, $this->superAdminCount) === null;
    }

    /**
     * Counted once per render, so the "last super admin" rule costs no query per row.
     */
    #[Computed]
    public function superAdminCount(): int
    {
        return app(AccessRules::class)->superAdminCount();
    }

    #[Computed]
    public function deletingUser(): ?AdminUser
    {
        return $this->deletingUserId ? Hata::userModel()::find($this->deletingUserId) : null;
    }

    public function confirmDelete(int $userId): void
    {
        $user = Hata::userModel()::findOrFail($userId);

        $this->authorize('delete', $user);

        $this->deletingUserId = $user->id;
        $this->showDeleteModal = true;
    }

    public function deleteUser(DeleteUser $deleteUser): void
    {
        $user = Hata::userModel()::findOrFail($this->deletingUserId);

        $this->authorize('delete', $user);

        try {
            $deleteUser->handle(Auth::user(), $user);

            Flux::toast(variant: 'success', text: __('hata::users.messages.deleted'));
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
            <flux:heading size="xl" level="1">{{ __('hata::users.index.title') }}</flux:heading>
            <flux:subheading>{{ __('hata::users.index.description') }}</flux:subheading>
        </div>

        @can('create', \Hata\Hata::userModel())
            <flux:button variant="primary" icon="plus" :href="route('admin.users.create')" wire:navigate>
                {{ __('hata::users.index.create') }}
            </flux:button>
        @endcan
    </div>

    <div class="flex flex-col gap-3 sm:flex-row">
        <flux:input
            wire:model.live.debounce.300ms="search"
            icon="magnifying-glass"
            :placeholder="__('hata::users.index.search')"
            :aria-label="__('hata::users.index.search')"
            clearable
            class="sm:max-w-sm"
        />

        <flux:select wire:model.live="role" :aria-label="__('hata::users.index.filter_by_role')" class="sm:max-w-56">
            <flux:select.option value="">{{ __('hata::users.index.all_roles') }}</flux:select.option>
            @foreach ($this->roles as $roleOption)
                <flux:select.option :value="$roleOption->id" wire:key="role-filter-{{ $roleOption->id }}">{{ $roleOption->displayName() }}</flux:select.option>
            @endforeach
        </flux:select>
    </div>

    @if ($this->users->isEmpty())
        <x-hata::admin.empty-state
            icon="users"
            :heading="$this->hasAnyUsers ? __('hata::users.index.no_results.title') : __('hata::users.index.empty.title')"
            :description="$this->hasAnyUsers ? __('hata::users.index.no_results.description') : __('hata::users.index.empty.description')"
        />
    @else
        <flux:table :paginate="$this->users">
            <flux:table.columns>
                <flux:table.column sortable :sorted="$sortBy === 'name'" :direction="$sortDirection" wire:click="sort('name')">{{ __('hata::users.index.columns.user') }}</flux:table.column>
                @foreach ($this->listColumns as $column)
                    @include('hata::partials.admin.lists.column-header', ['column' => $column, 'sortBy' => $sortBy, 'direction' => $sortDirection])
                @endforeach
                <flux:table.column align="end"><span class="sr-only">{{ __('hata::users.index.columns.actions') }}</span></flux:table.column>
            </flux:table.columns>

            <flux:table.rows>
                @foreach ($this->users as $user)
                    <flux:table.row :key="$user->id">
                        <flux:table.cell>
                            <div class="flex items-center gap-3">
                                <flux:avatar size="sm" :name="$user->name" :initials="$user->initials()" />
                                <div class="min-w-0">
                                    <div class="flex items-center gap-2 font-medium text-zinc-800 dark:text-white">
                                        @if ($this->canEdit($user))
                                            <a href="{{ route('admin.users.edit', $user) }}" wire:navigate class="truncate hover:underline" data-test="edit-user-link-{{ $user->id }}">{{ $user->name }}</a>
                                        @else
                                            <span class="truncate">{{ $user->name }}</span>
                                        @endif
                                        @if ($user->is(auth()->user()))
                                            <flux:badge size="sm" color="zinc">{{ __('hata::users.index.you') }}</flux:badge>
                                        @endif
                                    </div>
                                    <div class="truncate text-xs text-zinc-500 md:hidden dark:text-zinc-400">{{ $user->email }}</div>
                                </div>
                            </div>
                        </flux:table.cell>
                        @foreach ($this->listColumns as $column)
                            @include('hata::partials.admin.lists.column-cell', ['column' => $column, 'record' => $user, 'context' => $this->listContext()])
                        @endforeach
                        <flux:table.cell align="end">
                            <x-hata::admin.row-actions data-test="user-actions-{{ $user->id }}">
                                @if ($this->canEdit($user))
                                    <flux:menu.item icon="pencil-square" :href="route('admin.users.edit', $user)" wire:navigate>{{ __('hata::common.actions.edit') }}</flux:menu.item>
                                @endif
                                @if ($this->canDelete($user))
                                    <flux:menu.item variant="danger" icon="trash" wire:click="confirmDelete({{ $user->id }})" data-test="delete-user-{{ $user->id }}">{{ __('hata::common.actions.delete') }}</flux:menu.item>
                                @endif
                            </x-hata::admin.row-actions>
                        </flux:table.cell>
                    </flux:table.row>
                @endforeach
            </flux:table.rows>
        </flux:table>
    @endif

    <x-hata::confirm-modal
        wire:model.self="showDeleteModal"
        :heading="__('hata::users.delete.title')"
        :confirm-label="__('hata::users.delete.submit')"
        confirm-action="deleteUser"
    >
        {{ __('hata::users.delete.confirm', ['name' => $this->deletingUser?->name]) }}
    </x-hata::confirm-modal>
</section>

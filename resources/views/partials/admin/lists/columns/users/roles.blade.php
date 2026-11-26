<div class="flex flex-wrap gap-1">
    @forelse ($record->roles as $userRole)
        <flux:badge size="sm" :color="$userRole->isSuperAdmin() ? 'amber' : 'zinc'" :class="$userRole->isSuperAdmin() ? '' : 'bg-accent/10! text-accent-content! dark:bg-accent/20!'">{{ $userRole->displayName() }}</flux:badge>
    @empty
        <flux:text size="sm" variant="subtle">{{ __('hata::users.index.no_roles') }}</flux:text>
    @endforelse
</div>

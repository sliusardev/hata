{{-- The registered bulk actions (Admin::bulkActions()) as buttons of a list's bulk bar, after the list's own. Needs the HasListExtensions trait. --}}
@foreach ($this->listBulkActions as $action)
    <flux:button size="sm" :icon="$action->icon" :variant="$action->danger ? 'danger' : null" x-on:click="$wire.confirmBulkAction('{{ $action->key }}')" wire:key="bulk-action-{{ $action->key }}" data-test="bulk-action-{{ $action->key }}">{{ __($action->label) }}</flux:button>
@endforeach

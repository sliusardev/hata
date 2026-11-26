{{-- The registered row actions (Admin::rowActions()) in a row's "⋮" menu, after the list's own items. Needs the HasListExtensions trait; receives `record`, the list's `context` and `hasOwn` (whether the menu has items before them). The list skips the include when no action is registered. --}}
@php($visibleRowActions = array_filter($this->listRowActions, fn ($action): bool => $action->allows(auth()->user(), $record, $context)))
@if ($visibleRowActions !== [])
    @if ($hasOwn)
        <flux:menu.separator />
    @endif
    @foreach ($visibleRowActions as $action)
        @if ($action->isLink())
            <flux:menu.item :icon="$action->icon" :href="($action->url)($record)" :target="$action->newTab ? '_blank' : null" wire:key="row-action-{{ $action->key }}-{{ $record->getKey() }}" data-test="row-action-{{ $action->key }}-{{ $record->getKey() }}">{{ __($action->label) }}</flux:menu.item>
        @else
            <flux:menu.item :icon="$action->icon" :variant="$action->danger ? 'danger' : null" wire:click="confirmRowAction('{{ $action->key }}', {{ $record->getKey() }})" wire:key="row-action-{{ $action->key }}-{{ $record->getKey() }}" data-test="row-action-{{ $action->key }}-{{ $record->getKey() }}">{{ __($action->label) }}</flux:menu.item>
        @endif
    @endforeach
@endif

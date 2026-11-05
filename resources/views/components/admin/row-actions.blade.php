{{--
    Per-record actions in a table row: a "⋮" button that opens a dropdown menu.
    Pass <flux:menu.item> elements as the slot. Nothing is rendered when the slot is empty
    (the actor may perform no action on this record).
--}}
@props([
    'label' => null,
])

@if ($slot->hasActualContent())
    <flux:dropdown position="bottom" align="end" {{ $attributes }}>
        <flux:button size="sm" variant="ghost" icon="ellipsis-vertical" :aria-label="$label ?? __('common.actions.more')" />

        <flux:menu>
            {{ $slot }}
        </flux:menu>
    </flux:dropdown>
@endif

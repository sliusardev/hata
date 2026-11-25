@php
    $topbar = app(Hata\Support\Admin\AdminTopbar::class)->for(auth()->user());
@endphp

@foreach (['start', 'end'] as $side)
    @if ($side === 'end')
        <flux:spacer />
    @endif

    @foreach ($topbar[$side] as $item)
        @if ($item->component !== null)
            <x-dynamic-component :component="$item->component" wire:key="topbar-{{ $item->key }}" />
        @else
            <flux:button variant="ghost" size="sm" :icon="$item->icon" :href="$item->href()"
                :target="$item->newTab ? '_blank' : null" :rel="$item->newTab ? 'noopener' : null"
                wire:key="topbar-{{ $item->key }}">
                {{ __($item->label) }}
            </flux:button>
        @endif
    @endforeach
@endforeach

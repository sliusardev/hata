@props([
    'sidebar' => false,
])

@if($sidebar)
    <flux:sidebar.brand :name="config('app.name', 'Laravel')" {{ $attributes }}>
        <x-slot name="logo" class="h-8 rounded-none">
            <x-hata::site-logo class="h-8" />
        </x-slot>
    </flux:sidebar.brand>
@else
    <flux:brand :name="config('app.name', 'Laravel')" {{ $attributes }}>
        <x-slot name="logo" class="h-8 rounded-none">
            <x-hata::site-logo class="h-8" />
        </x-slot>
    </flux:brand>
@endif

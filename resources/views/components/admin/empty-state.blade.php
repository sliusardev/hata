@props([
    'icon' => 'inbox',
    'heading',
    'description' => null,
])

<div {{ $attributes->class('rounded-xl border border-dashed border-zinc-300 p-10 text-center dark:border-zinc-700') }}>
    <div class="mx-auto mb-4 flex size-12 items-center justify-center rounded-full bg-zinc-100 dark:bg-zinc-800">
        <flux:icon :name="$icon" class="size-6 text-zinc-500 dark:text-zinc-400" />
    </div>
    <flux:heading>{{ $heading }}</flux:heading>
    @if ($description)
        <flux:text class="mt-1">{{ $description }}</flux:text>
    @endif
    {{ $slot }}
</div>

@props([
    'title',
    'loading' => false,
    'failed' => false,
    'content' => null,
])

<flux:card class="flex h-full min-h-40 flex-col gap-3">
    <flux:heading level="2" size="lg">{{ $title }}</flux:heading>

    @if ($loading)
        <div class="flex flex-1 flex-col gap-3" aria-busy="true">
            <div class="h-4 w-2/3 animate-pulse rounded bg-zinc-100 dark:bg-zinc-800"></div>
            <div class="h-4 w-1/2 animate-pulse rounded bg-zinc-100 dark:bg-zinc-800"></div>
            <div class="h-4 w-3/4 animate-pulse rounded bg-zinc-100 dark:bg-zinc-800"></div>
        </div>
    @elseif ($failed)
        <flux:callout variant="warning" icon="exclamation-triangle" :heading="__('hata::dashboard.widget_failed')" />
    @else
        <div class="flex-1">{{ $content }}</div>
    @endif
</flux:card>

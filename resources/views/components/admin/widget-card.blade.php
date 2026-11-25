@props([
    'title',
    'icon' => null,
    'loading' => false,
    'failed' => false,
    'content' => null,
])

<flux:card class="flex h-full min-h-40 flex-col gap-5 p-5!">
    <div class="flex items-center gap-3">
        @if ($icon)
            <div class="flex size-9 shrink-0 items-center justify-center rounded-lg bg-accent/10 text-accent-content dark:bg-accent/15">
                <flux:icon :icon="$icon" variant="outline" class="size-5" />
            </div>
        @endif
        <flux:heading level="2" size="lg" class="truncate">{{ $title }}</flux:heading>
    </div>

    @if ($loading)
        <div class="flex flex-1 flex-col gap-3" aria-busy="true">
            <div class="h-4 w-2/3 animate-pulse rounded bg-zinc-100 dark:bg-zinc-700/60"></div>
            <div class="h-4 w-1/2 animate-pulse rounded bg-zinc-100 dark:bg-zinc-700/60"></div>
            <div class="h-4 w-3/4 animate-pulse rounded bg-zinc-100 dark:bg-zinc-700/60"></div>
        </div>
    @elseif ($failed)
        <flux:callout variant="warning" icon="exclamation-triangle" :heading="__('hata::dashboard.widget_failed')" />
    @else
        <div class="flex-1">{!! $content !!}</div>
    @endif
</flux:card>

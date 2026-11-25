@if ($entries->isEmpty())
    <div class="flex flex-col items-center justify-center gap-2 py-6 text-center">
        <flux:icon.clock class="size-8 text-zinc-300 dark:text-zinc-600" />
        <flux:text>{{ __('hata::dashboard.widgets.empty') }}</flux:text>
    </div>
@else
    <ul class="-mx-2 flex flex-col">
        @foreach ($entries as $entry)
            @php($updated = $entry->updated_at?->copy()->tz($timezone))
            @php($type = $types[$entry->type])
            <li>
                <a
                    href="{{ route('admin.content.edit', ['type' => $entry->type, 'entry' => $entry->id]) }}"
                    wire:navigate
                    class="group flex items-center gap-3 rounded-lg px-2 py-2.5 transition hover:bg-zinc-50 dark:hover:bg-white/[0.04]"
                >
                    <div class="flex size-9 shrink-0 items-center justify-center rounded-lg bg-zinc-100 text-zinc-500 group-hover:bg-accent/10 group-hover:text-accent-content dark:bg-zinc-700/60 dark:text-zinc-400">
                        <flux:icon :icon="$type->icon()" variant="outline" class="size-4" />
                    </div>

                    <div class="min-w-0 flex-1">
                        <div class="truncate text-sm font-medium text-zinc-800 group-hover:text-accent-content dark:text-white">{{ $entry->title }}</div>
                        <div class="flex items-center gap-1.5 text-xs text-zinc-500 dark:text-zinc-400">
                            <span>{{ $type->label() }}</span>
                            @if ($showLocale)
                                <span aria-hidden="true">·</span>
                                <span class="uppercase">{{ $entry->locale }}</span>
                            @endif
                        </div>
                    </div>

                    @if ($entry->status === \Hata\Enums\EntryStatus::Draft)
                        <flux:badge size="sm" color="amber" inset="top bottom">{{ __('hata::dashboard.widgets.recent_changes.draft') }}</flux:badge>
                    @elseif ($type->supports('publication'))
                        <flux:badge size="sm" color="green" inset="top bottom">{{ __('hata::dashboard.widgets.recent_changes.published') }}</flux:badge>
                    @endif

                    <time class="w-28 shrink-0 text-right text-xs text-zinc-500 max-sm:hidden dark:text-zinc-400" datetime="{{ $updated?->toIso8601String() }}" title="{{ $updated?->isoFormat('LLL') }}">{{ $updated?->diffForHumans() }}</time>
                </a>
            </li>
        @endforeach
    </ul>
@endif

@if ($entries->isEmpty())
    <flux:text>{{ __('hata::dashboard.widgets.empty') }}</flux:text>
@else
    <ul class="-my-2 divide-y divide-zinc-200 dark:divide-zinc-700">
        @foreach ($entries as $entry)
            @php($updated = $entry->updated_at?->copy()->tz($timezone))
            <li class="flex items-center justify-between gap-3 py-2 text-sm">
                <div class="min-w-0">
                    <a href="{{ route('admin.content.edit', ['type' => $entry->type, 'entry' => $entry->id]) }}" wire:navigate class="block truncate font-medium text-zinc-800 hover:text-accent-content dark:text-white">{{ $entry->title }}</a>
                    <div class="flex items-center gap-2 text-xs text-zinc-500 dark:text-zinc-400">
                        <span>{{ $types[$entry->type]->label() }}</span>
                        @if ($showLocale)
                            <span class="uppercase">{{ $entry->locale }}</span>
                        @endif
                        @if ($entry->status === \Hata\Enums\EntryStatus::Draft)
                            <flux:badge size="sm" color="amber">{{ __('hata::dashboard.widgets.recent_changes.draft') }}</flux:badge>
                        @endif
                    </div>
                </div>
                <time class="shrink-0 text-xs text-zinc-500 dark:text-zinc-400" datetime="{{ $updated?->toIso8601String() }}" title="{{ $updated?->isoFormat('LLL') }}">{{ $updated?->diffForHumans() }}</time>
            </li>
        @endforeach
    </ul>
@endif

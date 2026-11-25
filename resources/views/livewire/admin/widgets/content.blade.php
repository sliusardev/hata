@if ($rows === [])
    <flux:text>{{ __('hata::dashboard.widgets.empty') }}</flux:text>
@else
    <ul class="-my-2 divide-y divide-zinc-200 dark:divide-zinc-700">
        @foreach ($rows as $row)
            <li>
                <a href="{{ $row['url'] }}" wire:navigate class="flex items-center justify-between gap-3 py-2 text-sm hover:text-accent-content">
                    <span class="font-medium text-zinc-800 dark:text-white">{{ $row['label'] }}</span>
                    <span class="flex items-center gap-3 tabular-nums text-zinc-500 dark:text-zinc-400">
                        @if ($row['publication'])
                            <span title="{{ __('hata::dashboard.widgets.content.published') }}">{{ $row['published'] }}</span>
                            <span class="min-w-8 text-right" title="{{ __('hata::dashboard.widgets.content.drafts') }}">
                                @if ($row['drafts'] > 0)
                                    <flux:badge size="sm" color="amber">{{ $row['drafts'] }}</flux:badge>
                                @else
                                    {{ $row['drafts'] }}
                                @endif
                            </span>
                        @else
                            <span>{{ $row['total'] }}</span>
                        @endif
                    </span>
                </a>
            </li>
        @endforeach
    </ul>
    <p class="mt-3 text-xs text-zinc-500 dark:text-zinc-400">{{ __('hata::dashboard.widgets.content.legend') }}</p>
@endif

@if ($rows === [])
    <div class="flex flex-col items-center justify-center gap-2 py-6 text-center">
        <flux:icon.document-text class="size-8 text-zinc-300 dark:text-zinc-600" />
        <flux:text>{{ __('hata::dashboard.widgets.empty') }}</flux:text>
    </div>
@else
    <ul class="grid grid-cols-1 gap-3 sm:grid-cols-2 xl:grid-cols-4">
        @foreach ($rows as $row)
            <li>
                <a
                    href="{{ $row['url'] }}"
                    wire:navigate
                    class="group flex h-full flex-col gap-4 rounded-xl border border-zinc-200 bg-zinc-50/60 p-4 transition hover:border-accent/40 hover:bg-white hover:shadow-sm dark:border-zinc-700 dark:bg-white/[0.03] dark:hover:border-accent/40 dark:hover:bg-white/[0.06]"
                >
                    <div class="flex items-center justify-between gap-3">
                        <span class="flex items-center gap-2 text-sm font-medium text-zinc-600 dark:text-zinc-300">
                            <flux:icon :icon="$row['icon']" variant="outline" class="size-4 text-zinc-400 group-hover:text-accent-content dark:text-zinc-500" />
                            {{ $row['label'] }}
                        </span>
                        <flux:icon.arrow-up-right class="size-4 text-zinc-300 opacity-0 transition group-hover:opacity-100 dark:text-zinc-600" />
                    </div>

                    <div class="text-3xl font-semibold tabular-nums text-zinc-900 dark:text-white">{{ $row['total'] }}</div>

                    @if ($row['publication'])
                        <div class="flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-zinc-500 dark:text-zinc-400">
                            <span class="flex items-center gap-1.5">
                                <span class="size-1.5 rounded-full bg-emerald-500"></span>
                                {{ trans_choice('hata::dashboard.widgets.content.published_count', $row['published'], ['count' => $row['published']]) }}
                            </span>
                            <span class="flex items-center gap-1.5 {{ $row['drafts'] > 0 ? 'text-amber-600 dark:text-amber-400' : '' }}">
                                <span class="size-1.5 rounded-full {{ $row['drafts'] > 0 ? 'bg-amber-500' : 'bg-zinc-300 dark:bg-zinc-600' }}"></span>
                                {{ trans_choice('hata::dashboard.widgets.content.drafts_count', $row['drafts'], ['count' => $row['drafts']]) }}
                            </span>
                        </div>
                    @else
                        <div class="text-xs text-zinc-500 dark:text-zinc-400">{{ __('hata::dashboard.widgets.content.terms') }}</div>
                    @endif
                </a>
            </li>
        @endforeach
    </ul>
@endif

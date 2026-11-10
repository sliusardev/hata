{{--
    Grid view of the media library: square thumbnails, the first $gridLimit files with "Show more".
    Expects: $media (paginator), $selectedId (the file open in the details panel).
--}}
<div class="space-y-6">
    <div class="grid grid-cols-2 gap-4 sm:grid-cols-3 xl:grid-cols-4 2xl:grid-cols-5">
        @foreach ($media as $item)
            {{-- Task 10: selecting a tile opens the details panel; Task 11 adds the "Select several" checkmark. --}}
            <button
                type="button"
                wire:key="media-tile-{{ $item->id }}"
                wire:click="$set('selectedId', {{ $item->id }})"
                class="group min-w-0 text-start focus:outline-none"
                aria-label="{{ $item->name }}"
                @if ($selectedId === $item->id) aria-pressed="true" @endif
                data-test="media-tile"
            >
                <div @class([
                    'relative aspect-square overflow-hidden rounded-lg bg-zinc-100 ring-1 ring-black/5 ring-inset transition dark:bg-white/5 dark:ring-white/10',
                    'group-hover:ring-zinc-300 group-focus-visible:ring-2 group-focus-visible:ring-accent dark:group-hover:ring-white/20',
                    'ring-2! ring-accent!' => $selectedId === $item->id,
                ])>
                    @if ($thumbUrl = $item->thumbUrl())
                        <img src="{{ $thumbUrl }}" alt="{{ $item->altFor(app()->getLocale()) }}" loading="lazy" class="size-full object-cover" />
                    @else
                        <div class="flex size-full flex-col items-center justify-center gap-2 text-zinc-400 dark:text-zinc-500">
                            <flux:icon :name="$item->kind->icon()" variant="outline" class="size-10" />
                            <span class="text-xs font-semibold tracking-wide text-zinc-500 dark:text-zinc-400">{{ $item->extension() }}</span>
                        </div>
                    @endif

                    @if ($item->usages_count === 0)
                        <flux:badge size="sm" color="amber" class="absolute start-2 bottom-2">{{ __('media.index.unused') }}</flux:badge>
                    @endif
                </div>
                <div class="mt-2 min-w-0 px-0.5">
                    <div class="truncate text-sm font-medium text-zinc-800 dark:text-white">{{ $item->name }}</div>
                    <div class="text-xs text-zinc-500 dark:text-zinc-400">{{ $item->humanSize() }}</div>
                </div>
            </button>
        @endforeach
    </div>

    @if ($media->hasMorePages())
        <div class="flex justify-center">
            <flux:button wire:click="showMore" data-test="media-show-more">
                {{ __('media.index.show_more', ['count' => min(40, $media->total() - $media->count())]) }}
            </flux:button>
        </div>
    @endif
</div>

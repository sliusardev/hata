{{--
    Grid view of the media library: square thumbnails, the first $gridLimit files with "Show more".
    Expects: $media (paginator), $selectedId (the file open in the details panel),
    $selected (ids picked for bulk actions), $selecting (a click picks the tile instead of opening it),
    $gridStep (files added by "Show more"), $canShowMore (false once the grid reached its cap; then a hint points to the list),
    $canMove (tiles can be dragged onto a folder in the tree).
--}}
<div class="space-y-6">
    <div class="grid grid-cols-2 gap-4 sm:grid-cols-3 xl:grid-cols-4 2xl:grid-cols-5">
        @foreach ($media as $item)
            @php $isPicked = in_array($item->id, $selected, true); @endphp
            <button
                type="button"
                wire:key="media-tile-{{ $item->id }}"
                wire:click="{{ $selecting ? 'toggleSelected' : 'select' }}({{ $item->id }})"
                @if ($canMove)
                    draggable="true"
                    x-on:dragstart="event.dataTransfer.effectAllowed = 'move'; event.dataTransfer.setData('application/x-media-ids', JSON.stringify([{{ $item->id }}]))"
                @endif
                class="group min-w-0 text-start focus:outline-none"
                aria-label="{{ $item->name }}"
                @if ($selecting)
                    aria-pressed="{{ $isPicked ? 'true' : 'false' }}"
                @elseif ($selectedId === $item->id)
                    aria-pressed="true"
                @endif
                data-test="media-tile"
            >
                <div @class([
                    'relative aspect-square overflow-hidden rounded-lg bg-zinc-100 ring-1 ring-black/5 ring-inset transition dark:bg-white/5 dark:ring-white/10',
                    'group-hover:ring-zinc-300 group-focus-visible:ring-2 group-focus-visible:ring-accent dark:group-hover:ring-white/20',
                    'ring-2! ring-accent!' => $isPicked || (! $selecting && $selectedId === $item->id),
                ])>
                    @if ($thumbUrl = $item->thumbUrl())
                        <img src="{{ $thumbUrl }}" alt="{{ $item->altFor(app()->getLocale()) }}" loading="lazy" class="size-full object-cover" />
                    @else
                        <div class="flex size-full flex-col items-center justify-center gap-2 text-zinc-400 dark:text-zinc-500">
                            <flux:icon :name="$item->kind->icon()" variant="outline" class="size-10" />
                            <span class="text-xs font-semibold tracking-wide text-zinc-500 dark:text-zinc-400">{{ $item->extension() }}</span>
                        </div>
                    @endif

                    @if ($isPicked)
                        <div class="absolute end-2 top-2 flex size-6 items-center justify-center rounded-full bg-zinc-800 text-white ring-2 ring-white dark:bg-white dark:text-zinc-800 dark:ring-zinc-900" data-test="media-tile-picked">
                            <flux:icon name="check" variant="micro" class="size-4" />
                        </div>
                    @elseif ($selecting)
                        <div class="absolute end-2 top-2 size-6 rounded-full bg-white/80 ring-2 ring-zinc-400 dark:bg-zinc-900/60"></div>
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
            @if ($canShowMore)
                <flux:button wire:click="showMore" data-test="media-show-more">
                    {{ __('media.index.show_more', ['count' => min($gridStep, $media->total() - $media->count())]) }}
                </flux:button>
            @else
                <flux:text data-test="media-grid-capped">
                    {{ __('media.index.grid_capped', ['shown' => $media->count(), 'total' => $media->total()]) }}
                </flux:text>
            @endif
        </div>
    @endif
</div>

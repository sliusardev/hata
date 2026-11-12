{{--
    Grid view of the media library: square thumbnails, the first $gridLimit files with "Show more".
    Expects: $media (paginator), $selectedId (the file open in the details panel),
    $selected (ids picked for bulk actions), $selecting (a click picks the tile instead of opening it; a click also picks while anything is selected),
    $gridStep (files added by "Show more"), $canShowMore (false once the grid reached its cap; then a hint points to the list),
    $canMove (tiles can be dragged onto a folder in the tree).
    Drawing a box on empty space selects the tiles it touches (resources/js/media-marquee.js).
    Rendered inside the page's "files" island: tile clicks and "Show more" pass true (narrow), so the server
    renders only the islands the action names instead of the whole page.
--}}
@php $clickPicks = $selecting || $selected !== []; @endphp

{{-- The key keeps a morph from turning the empty state's element into this one: Alpine would then set up the
     children before this x-data exists ("box is not defined") and the mouse box would stay dead until a reload. --}}
<div
    wire:key="media-grid"
    class="relative -m-4 min-h-[50vh] space-y-6 p-4 pb-24 select-none"
    x-data="mediaMarquee"
    x-on:pointerdown="begin($event)"
    data-media-area
    data-test="media-grid"
    x-on:click="mediaDetailsClose.emptyClick($event, $wire)"
>
    <div
        x-show="box"
        x-cloak
        x-bind:style="boxStyle()"
        class="pointer-events-none fixed z-30 rounded-sm border border-accent bg-accent/10"
        aria-hidden="true"
    ></div>

    <div class="grid grid-cols-2 gap-5 sm:grid-cols-3 xl:grid-cols-4 2xl:grid-cols-[repeat(auto-fill,minmax(13rem,1fr))]">
        @foreach ($media as $item)
            @php $isPicked = in_array($item->id, $selected, true); @endphp
            <button
                type="button"
                wire:key="media-tile-{{ $item->id }}"
                data-media-id="{{ $item->id }}"
                {{-- Shift + mousedown: no text selection across tiles during a Shift + click range (a Shift-drag of a tile is intentionally not supported). --}}
                x-on:mousedown="if ($event.shiftKey) $event.preventDefault()"
                x-on:click="
                    if ($event.shiftKey) { $wire.pick({{ $item->id }}, 'range', true) }
                    else if ($event.metaKey || $event.ctrlKey) { $wire.pick({{ $item->id }}, 'toggle', true) }
                    else { $wire.{{ $clickPicks ? 'toggleSelected' : 'select' }}({{ $item->id }}, true) }
                "
                @if ($canMove)
                    draggable="true"
                    x-on:dragstart="mediaDragStart($event, {{ $item->id }}, $wire)"
                @endif
                @class([
                    'group min-w-0 rounded-xl p-1.5 text-start transition-colors',
                    'hover:bg-zinc-100 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-dashed focus-visible:outline-zinc-500 dark:hover:bg-white/5 dark:focus-visible:outline-zinc-300',
                    'data-[boxed=true]:bg-zinc-200/70 data-[boxed=true]:outline-2 data-[boxed=true]:outline-accent dark:data-[boxed=true]:bg-white/10',
                    'bg-zinc-200/70! outline-2 outline-accent dark:bg-white/10!' => $isPicked || (! $selecting && $selectedId === $item->id),
                ])
                data-active="{{ $isPicked || (! $selecting && $selectedId === $item->id) ? 'true' : 'false' }}"
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
                    'group-hover:ring-zinc-300 dark:group-hover:ring-white/20',
                ])>
                    @include('partials.admin.media.thumb', ['item' => $item])

                    @if ($isPicked)
                        <div class="absolute end-2 top-2 flex size-6 items-center justify-center rounded-full bg-zinc-800 text-white ring-2 ring-white dark:bg-white dark:text-zinc-800 dark:ring-zinc-900" data-test="media-tile-picked">
                            <flux:icon name="check" variant="micro" class="size-4" />
                        </div>
                    @elseif ($clickPicks)
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
                <flux:button x-on:click="$wire.showMore(true)" data-test="media-show-more">
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

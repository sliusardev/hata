{{--
    Folder navigation of the media library.
    Expects: $folder (null, 'none' or a folder id), $folders (rows of folder + depth), $totals (all, none).
--}}
@php
    $itemClasses = 'flex h-9 w-full items-center gap-2 rounded-lg px-2.5 text-start text-sm text-zinc-700 hover:bg-zinc-800/5 dark:text-zinc-300 dark:hover:bg-white/10';
    $currentClasses = 'bg-zinc-800/5 font-medium text-zinc-900 dark:bg-white/10 dark:text-white';
@endphp

<nav aria-label="{{ __('media.index.folders.label') }}" class="shrink-0 space-y-0.5 md:w-56">
    <button type="button" wire:click="$set('folder', null)" @class([$itemClasses, $currentClasses => $folder === null]) @if ($folder === null) aria-current="page" @endif>
        <flux:icon name="squares-2x2" variant="outline" class="size-4 shrink-0 text-zinc-400" />
        <span class="flex-1 truncate">{{ __('media.index.folders.all') }}</span>
        <span class="text-xs text-zinc-400 tabular-nums">{{ $totals['all'] }}</span>
    </button>

    <button type="button" wire:click="$set('folder', 'none')" @class([$itemClasses, $currentClasses => $folder === 'none']) @if ($folder === 'none') aria-current="page" @endif>
        <flux:icon name="inbox" variant="outline" class="size-4 shrink-0 text-zinc-400" />
        <span class="flex-1 truncate">{{ __('media.index.folders.none') }}</span>
        <span class="text-xs text-zinc-400 tabular-nums">{{ $totals['none'] }}</span>
    </button>

    @if ($folders !== [])
        <div class="flex items-center justify-between px-2.5 pt-4 pb-1">
            <span class="text-[11px] font-semibold tracking-wider text-zinc-400 uppercase dark:text-zinc-500">{{ __('media.index.folders.heading') }}</span>
            {{-- Task 10: the "+" (new folder) button goes here. --}}
        </div>
    @endif

    @foreach ($folders as $row)
        @php $isCurrent = $folder === (string) $row['folder']->id; @endphp
        <button
            type="button"
            wire:key="folder-{{ $row['folder']->id }}"
            wire:click="$set('folder', '{{ $row['folder']->id }}')"
            @class([$itemClasses, $currentClasses => $isCurrent])
            style="padding-inline-start: {{ 0.625 + $row['depth'] * 1.25 }}rem"
            @if ($isCurrent) aria-current="page" @endif
            data-test="media-folder-{{ $row['folder']->id }}"
        >
            <flux:icon name="folder" variant="outline" class="size-4 shrink-0 text-zinc-400" />
            <span class="flex-1 truncate">{{ $row['folder']->name }}</span>
            <span class="text-xs text-zinc-400 tabular-nums">{{ $row['folder']->media_count }}</span>
        </button>
    @endforeach
</nav>

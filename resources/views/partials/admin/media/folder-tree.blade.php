{{--
    Folder navigation of the media library.
    Expects: $folder (null, 'none' or a folder id), $folders (rows of folder + depth), $totals (all, none),
    $canManageFolders (shows "+" and makes "No folder" and the folders drop targets for dragged tiles).
--}}
@php
    $itemClasses = 'flex h-9 w-full items-center gap-2 rounded-lg px-2.5 text-start text-sm text-zinc-700 hover:bg-zinc-800/5 dark:text-zinc-300 dark:hover:bg-white/10';
    $currentClasses = 'bg-zinc-800/5 font-medium text-zinc-900 dark:bg-white/10 dark:text-white';
@endphp

<nav
    aria-label="{{ __('media.index.folders.label') }}"
    class="shrink-0 space-y-0.5 md:w-56"
    @if ($canManageFolders)
        x-data="{
            over: null,
            accepts(event) { return event.dataTransfer.types.includes('application/x-media-ids') },
            enter(event, target) { if (this.accepts(event)) { event.preventDefault(); this.over = target } },
            drop(event, folderId) {
                this.over = null;
                const ids = event.dataTransfer.getData('application/x-media-ids');
                if (ids) { $wire.moveTo(JSON.parse(ids), folderId) }
            },
        }"
    @endif
>
    <button type="button" wire:click="$set('folder', null)" @class([$itemClasses, $currentClasses => $folder === null]) @if ($folder === null) aria-current="page" @endif>
        <flux:icon name="squares-2x2" variant="outline" class="size-4 shrink-0 text-zinc-400" />
        <span class="flex-1 truncate">{{ __('media.index.folders.all') }}</span>
        <span class="text-xs text-zinc-400 tabular-nums">{{ $totals['all'] }}</span>
    </button>

    <button
        type="button"
        wire:click="$set('folder', 'none')"
        @class([$itemClasses, $currentClasses => $folder === 'none'])
        @if ($folder === 'none') aria-current="page" @endif
        @if ($canManageFolders)
            x-on:dragover="enter($event, 'none')"
            x-on:dragleave="if (! $el.contains($event.relatedTarget)) over = null"
            x-on:drop.prevent="drop($event, null)"
            x-bind:class="over === 'none' && 'ring-2 ring-accent ring-inset'"
        @endif
    >
        <flux:icon name="inbox" variant="outline" class="size-4 shrink-0 text-zinc-400" />
        <span class="flex-1 truncate">{{ __('media.index.folders.none') }}</span>
        <span class="text-xs text-zinc-400 tabular-nums">{{ $totals['none'] }}</span>
    </button>

    @if ($folders !== [] || $canManageFolders)
        <div class="flex h-9 items-center justify-between ps-2.5 pt-3">
            <span class="text-[11px] font-semibold tracking-wider text-zinc-400 uppercase dark:text-zinc-500">{{ __('media.index.folders.heading') }}</span>
            @if ($canManageFolders)
                <flux:button size="xs" variant="ghost" icon="plus" wire:click="openNewFolder" :aria-label="__('media.folders.new')" :tooltip="__('media.folders.new')" data-test="media-new-folder" />
            @endif
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
            @if ($canManageFolders)
                x-on:dragover="enter($event, {{ $row['folder']->id }})"
                x-on:dragleave="if (! $el.contains($event.relatedTarget)) over = null"
                x-on:drop.prevent="drop($event, {{ $row['folder']->id }})"
                x-bind:class="over === {{ $row['folder']->id }} && 'ring-2 ring-accent ring-inset'"
            @endif
            data-test="media-folder-{{ $row['folder']->id }}"
        >
            <flux:icon name="folder" variant="outline" class="size-4 shrink-0 text-zinc-400" />
            <span class="flex-1 truncate">{{ $row['folder']->name }}</span>
            <span class="text-xs text-zinc-400 tabular-nums">{{ $row['folder']->media_count }}</span>
        </button>
    @endforeach

    @if ($canManageFolders && $folders !== [])
        <flux:text size="sm" class="px-2.5 pt-3 text-xs">{{ __('media.folders.move_hint') }}</flux:text>
    @endif
</nav>

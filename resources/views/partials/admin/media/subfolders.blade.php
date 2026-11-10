{{--
    Subfolder tiles of the open folder, shown above the files in grid and list view.
    Expects: $subfolders (folders with media_count and children_count), $canMove (media.edit: the tiles are
    drop targets for files and folders, can be dragged as folders and get the folder "⋮" menu; see resources/js/media-folder-drag.js).
    Tiles are not files: no selection, no marquee (data-media-folder keeps the marquee away).
--}}
<div
    class="grid grid-cols-1 gap-2 sm:grid-cols-2 xl:grid-cols-3 2xl:grid-cols-4"
    role="group"
    aria-label="{{ __('media.index.subfolders.label') }}"
    data-test="media-subfolders"
    @if ($canMove)
        x-data="mediaFolderTiles"
        x-on:dragend.window="reset()"
    @endif
>
    @foreach ($subfolders as $subfolder)
        <div
            wire:key="subfolder-{{ $subfolder->id }}"
            data-media-folder="{{ $subfolder->id }}"
            class="group/tile relative flex min-w-0 items-center rounded-xl border border-zinc-200 bg-white hover:bg-zinc-50 dark:border-white/10 dark:bg-white/5 dark:hover:bg-white/10"
            @if ($canMove)
                draggable="true"
                x-on:dragstart="start($event, {{ $subfolder->id }})"
                x-on:dragover="enter($event, {{ $subfolder->id }})"
                x-on:dragleave="leave($event)"
                x-on:drop.prevent="drop($event, {{ $subfolder->id }})"
                x-bind:class="over === {{ $subfolder->id }} && 'ring-2 ring-accent ring-inset'"
            @endif
            data-test="media-subfolder-{{ $subfolder->id }}"
        >
            <div
                role="button"
                tabindex="0"
                title="{{ $subfolder->name }}"
                @class([
                    'flex min-w-0 flex-1 cursor-pointer items-center gap-3 rounded-xl py-2.5 ps-3 text-start focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-dashed focus-visible:outline-zinc-500 dark:focus-visible:outline-zinc-300',
                    'pe-3' => ! $canMove,
                    'pe-1' => $canMove,
                ])
                wire:click="$set('folder', '{{ $subfolder->id }}')"
                x-on:keydown.enter.prevent="$el.click()"
                x-on:keydown.space.prevent="$el.click()"
                data-test="media-subfolder-open-{{ $subfolder->id }}"
            >
                <flux:icon name="folder" variant="outline" class="size-6 shrink-0 text-zinc-400" />
                <div class="min-w-0 flex-1">
                    <div class="truncate text-sm font-medium text-zinc-800 dark:text-white" title="{{ $subfolder->name }}">{{ $subfolder->name }}</div>
                    <div class="truncate text-xs text-zinc-500 dark:text-zinc-400">
                        {{ trans_choice('media.index.subfolders.files', $subfolder->media_count) }} · {{ trans_choice('media.index.subfolders.folders', $subfolder->children_count) }}
                    </div>
                </div>
            </div>
            @if ($canMove)
                @include('partials.admin.media.folder-menu', ['folderId' => $subfolder->id, 'testId' => 'media-subfolder-actions-'.$subfolder->id, 'stop' => true, 'class' => 'pe-1.5'])
            @endif
        </div>
    @endforeach
</div>

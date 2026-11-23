{{--
    Bulk actions bar for the files picked in the list (checkboxes) or the grid ("Select several").
    Rendered inside the page's "bulk" island, so its buttons call $wire from Alpine instead of wire:click
    (Livewire would scope a wire: action to the island). Expects: $count (selected files), $canMove (may move files),
    $folders (rows of folder + depth, tree order).
    The "dark" class makes the Flux buttons inside use their dark style on the dark bar.
--}}
<div
    role="region"
    aria-label="{{ __('hata::media.bulk.label') }}"
    class="dark flex flex-wrap items-center gap-3 rounded-xl bg-zinc-800 py-2 ps-4 pe-2 text-white dark:bg-zinc-700"
    data-test="media-bulk-bar"
>
    <span class="text-sm font-medium" aria-live="polite">{{ trans_choice('hata::media.bulk.selected', $count) }}</span>
    <flux:button size="sm" variant="ghost" x-on:click="$wire.clearSelection()">{{ __('hata::media.bulk.clear') }}</flux:button>

    <flux:spacer />

    @if ($canMove)
        <flux:dropdown position="top" align="end">
            <flux:button size="sm" variant="outline" icon="folder-arrow-down" icon:trailing="chevron-down" data-test="media-bulk-move">{{ __('hata::media.bulk.move') }}</flux:button>
            <flux:menu class="max-h-80 overflow-y-auto">
                <flux:menu.item icon="inbox" x-on:click="$wire.moveSelected(null)" data-test="media-bulk-move-none">{{ __('hata::media.index.folders.none') }}</flux:menu.item>
                @if ($folders !== [])
                    <flux:menu.separator />
                    @foreach ($folders as $row)
                        <flux:menu.item
                            icon="folder"
                            wire:key="bulk-move-{{ $row['folder']->id }}"
                            x-on:click="$wire.moveSelected({{ $row['folder']->id }})"
                            style="padding-inline-start: {{ 0.5 + $row['depth'] * 1 }}rem"
                        >{{ $row['folder']->name }}</flux:menu.item>
                    @endforeach
                @endif
            </flux:menu>
        </flux:dropdown>
    @endif

    @if (class_exists(ZipArchive::class))
        <flux:button size="sm" variant="outline" icon="arrow-down-tray" x-on:click="$wire.downloadZip()" data-test="media-bulk-zip">{{ __('hata::media.bulk.zip') }}</flux:button>
    @endif

    @can('media.delete')
        <flux:button size="sm" variant="danger" icon="trash" x-on:click="$wire.confirmBulkDelete()" data-test="media-bulk-delete">{{ __('hata::media.bulk.delete') }}</flux:button>
    @endcan
</div>

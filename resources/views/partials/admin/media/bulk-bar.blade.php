{{--
    Bulk actions bar for the files picked in the list (checkboxes) or the grid ("Select several").
    Rendered inside the page component. Expects: $count (selected files), $canMove (may move files),
    $folders (rows of folder + depth, tree order).
    The "dark" class makes the Flux buttons inside use their dark style on the dark bar.
--}}
<div
    role="region"
    aria-label="{{ __('media.bulk.label') }}"
    class="dark flex flex-wrap items-center gap-3 rounded-xl bg-zinc-800 py-2 ps-4 pe-2 text-white dark:bg-zinc-700"
    data-test="media-bulk-bar"
>
    <span class="text-sm font-medium" aria-live="polite">{{ trans_choice('media.bulk.selected', $count) }}</span>
    <flux:button size="sm" variant="ghost" wire:click="clearSelection">{{ __('media.bulk.clear') }}</flux:button>

    <flux:spacer />

    @if ($canMove)
        <flux:dropdown position="top" align="end">
            <flux:button size="sm" variant="outline" icon="folder-arrow-down" icon:trailing="chevron-down" data-test="media-bulk-move">{{ __('media.bulk.move') }}</flux:button>
            <flux:menu class="max-h-80 overflow-y-auto">
                <flux:menu.item icon="inbox" wire:click="moveSelected(null)" data-test="media-bulk-move-none">{{ __('media.index.folders.none') }}</flux:menu.item>
                @if ($folders !== [])
                    <flux:menu.separator />
                    @foreach ($folders as $row)
                        <flux:menu.item
                            icon="folder"
                            wire:key="bulk-move-{{ $row['folder']->id }}"
                            wire:click="moveSelected({{ $row['folder']->id }})"
                            style="padding-inline-start: {{ 0.5 + $row['depth'] * 1 }}rem"
                        >{{ $row['folder']->name }}</flux:menu.item>
                    @endforeach
                @endif
            </flux:menu>
        </flux:dropdown>
    @endif

    @if (class_exists(ZipArchive::class))
        <flux:button size="sm" variant="outline" icon="arrow-down-tray" wire:click="downloadZip" data-test="media-bulk-zip">{{ __('media.bulk.zip') }}</flux:button>
    @endif

    @can('media.delete')
        <flux:button size="sm" variant="danger" icon="trash" wire:click="confirmBulkDelete" data-test="media-bulk-delete">{{ __('media.bulk.delete') }}</flux:button>
    @endcan
</div>

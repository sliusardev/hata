{{--
    Bulk actions bar for the files picked in the list (checkboxes) or the grid ("Select several").
    Rendered inside the page component. Expects: $count (selected files).
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

    @if (class_exists(ZipArchive::class))
        <flux:button size="sm" variant="outline" icon="arrow-down-tray" wire:click="downloadZip" data-test="media-bulk-zip">{{ __('media.bulk.zip') }}</flux:button>
    @endif

    @can('media.delete')
        <flux:button size="sm" variant="danger" icon="trash" wire:click="confirmBulkDelete" data-test="media-bulk-delete">{{ __('media.bulk.delete') }}</flux:button>
    @endcan
</div>

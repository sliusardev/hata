{{--
    The "⋮" menu of one folder: rename, move to…, delete (delete also needs media.delete).
    Used by the breadcrumb, the subfolder tiles and the folder tree, so the items live here only.
    Expects: $folderId, $testId (data-test of the dropdown). Render it only when the actor may manage folders.
    $class (optional) goes on the dropdown, $stop (optional, true) keeps clicks, keys and drags from reaching the tile or row around it.
--}}
@php $stop = $stop ?? false; @endphp
<span
    @if ($stop)
        x-on:click.stop
        x-on:keydown.stop
        x-on:dragstart.stop.prevent
    @endif
    @class(['inline-flex shrink-0', $class ?? null])
>
    <x-hata::admin.row-actions :label="__('media.folders.actions')" :data-test="$testId">
        <flux:menu.item icon="pencil-square" wire:click="openRenameFolder({{ $folderId }})">{{ __('media.folders.rename') }}</flux:menu.item>
        <flux:menu.item icon="folder-arrow-down" wire:click="openMoveFolder({{ $folderId }})">{{ __('media.folders.move') }}</flux:menu.item>
        @can('deleteFolders', Hata\Models\Media::class)
            <flux:menu.item icon="trash" variant="danger" wire:click="confirmFolderDelete({{ $folderId }})">{{ __('common.actions.delete') }}</flux:menu.item>
        @endcan
    </x-hata::admin.row-actions>
</span>

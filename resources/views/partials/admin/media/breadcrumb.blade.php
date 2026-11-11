{{--
    The path of the open folder: "All files" › ("No folder" | the folder's ancestors › the folder). Every part but the last leads back.
    Expects: $folder (the component's `folder` value: null or '' = all files, 'none' = no folder, otherwise a folder id),
    $crumbs (the open folder and its ancestors, top first), $rootValue (the JS literal that means "all files": 'null' on the
    library page, "''" in the media picker).
--}}
@php $atRoot = $folder === null || $folder === ''; @endphp
<flux:breadcrumbs :aria-label="__('media.index.folders.path')">
    @if ($atRoot)
        <flux:breadcrumbs.item>{{ __('media.index.folders.all') }}</flux:breadcrumbs.item>
    @else
        <flux:breadcrumbs.item href="#" wire:click.prevent="$set('folder', {{ $rootValue }})" data-test="media-breadcrumb-root">{{ __('media.index.folders.all') }}</flux:breadcrumbs.item>
        @if ($folder === 'none')
            <flux:breadcrumbs.item>{{ __('media.index.folders.none') }}</flux:breadcrumbs.item>
        @endif
        @foreach ($crumbs as $crumb)
            @if ($loop->last)
                <flux:breadcrumbs.item wire:key="crumb-{{ $crumb->id }}" title="{{ $crumb->name }}">{{ $crumb->name }}</flux:breadcrumbs.item>
            @else
                <flux:breadcrumbs.item href="#" wire:click.prevent="$set('folder', '{{ $crumb->id }}')" wire:key="crumb-{{ $crumb->id }}" title="{{ $crumb->name }}" data-test="media-breadcrumb-{{ $crumb->id }}">{{ $crumb->name }}</flux:breadcrumbs.item>
            @endif
        @endforeach
    @endif
</flux:breadcrumbs>

{{--
    Dialogs of the media library page: discard unsaved details, delete files, the folder name form, delete a folder.
    Rendered inside the page component, so it reads the component's state directly.
--}}
<x-confirm-modal
    wire:model.self="showDiscardModal"
    :heading="__('media.details.unsaved.heading')"
    :confirm-label="__('media.details.unsaved.discard')"
    confirm-action="discardChanges"
>
    {{ __('media.details.unsaved.body', ['name' => $this->selectedMedia?->name]) }}
</x-confirm-modal>

@php
    $deleting = $this->deletingMedia;
    $deletingUsages = $deleting->flatMap->usages;
    $deletingUsedFiles = $deleting->filter(fn ($media) => $media->usages->isNotEmpty())->count();
@endphp

<x-confirm-modal
    wire:model.self="showDeleteModal"
    wire:close="cancelPending"
    :heading="$deleting->count() > 1 ? __('media.delete.heading_many') : __('media.delete.heading')"
    :confirm-label="__('media.delete.confirm')"
    confirm-action="deleteConfirmed"
>
    @if ($deleting->count() === 1)
        {{ __('media.delete.body_one', ['name' => $deleting->first()->name]) }}
    @else
        {{ __('media.delete.body_many', ['files' => trans_choice('media.index.file_count', $deleting->count())]) }}
    @endif

    @if ($deletingUsages->isNotEmpty())
        <x-slot:extra>
            <div class="space-y-1.5 rounded-lg bg-amber-50 px-3 py-2.5 text-sm text-amber-800 dark:bg-amber-400/10 dark:text-amber-300">
                @if ($deleting->count() > 1)
                    {{-- A bulk selection states how many files are used; their places are listed in each file's details. --}}
                    <div class="font-medium">{{ trans_choice('media.delete.used_files', $deletingUsedFiles, ['total' => $deleting->count()]) }}</div>
                @else
                    <div class="font-medium">{{ trans_choice('media.delete.used', $deletingUsages->count()) }}</div>
                    <ul class="list-disc space-y-0.5 ps-5">
                        @foreach ($deletingUsages as $usage)
                            <li wire:key="deleting-usage-{{ $usage->id }}">{{ __('media.details.usage_item', ['type' => class_basename($usage->usable_type), 'id' => $usage->usable_id]) }}</li>
                        @endforeach
                    </ul>
                @endif
                <div>{{ __('media.delete.used_warning') }}</div>
            </div>
        </x-slot:extra>
    @endif
</x-confirm-modal>

<flux:modal wire:model.self="showFolderModal" class="max-w-md md:min-w-md">
    <form wire:submit="saveFolder" class="space-y-6">
        <flux:heading size="lg">{{ $renamingFolderId === null ? __('media.folders.new') : __('media.folders.rename_heading') }}</flux:heading>

        <flux:input wire:model="folderName" :label="__('media.folders.name')" maxlength="100" required autofocus data-test="media-folder-name" />

        <div class="flex justify-end gap-3">
            <flux:modal.close>
                <flux:button variant="outline">{{ __('common.actions.cancel') }}</flux:button>
            </flux:modal.close>
            <flux:button type="submit" variant="primary">
                {{ $renamingFolderId === null ? __('media.folders.create') : __('common.actions.save') }}
            </flux:button>
        </div>
    </form>
</flux:modal>

@php
    $deletingFolder = $this->deletingFolder;
    $summary = $this->folderDeletionSummary;
    $needsName = $summary !== null && $summary['files'] > 10;
@endphp

<x-confirm-modal
    wire:model.self="showFolderDeleteModal"
    wire:close="cancelPending"
    :heading="__('media.folders.delete.heading', ['name' => $deletingFolder?->name])"
    :confirm-label="__('media.folders.delete.confirm')"
    confirm-action="deleteFolder"
    :confirm-disabled="$needsName ? '$wire.confirmName.trim() !== '.Js::from($deletingFolder?->name) : null"
>
    @if ($summary !== null)
        {{ __('media.folders.delete.summary', [
            'files' => trans_choice('media.index.file_count', $summary['files']),
            'folders' => trans_choice('media.folders.delete.subfolders', $summary['folders'] - 1),
        ]) }}
    @endif

    @if ($summary !== null && ($summary['used'] > 0 || $needsName))
        <x-slot:extra>
            <div class="space-y-4">
                @if ($summary['used'] > 0)
                    <flux:callout variant="warning" icon="exclamation-triangle" :text="trans_choice('media.folders.delete.used', $summary['used'])" />
                @endif

                @if ($needsName)
                    <flux:input
                        wire:model="confirmName"
                        :label="__('media.folders.delete.type_name', ['name' => $deletingFolder?->name])"
                        autocomplete="off"
                        data-test="media-folder-confirm-name"
                    />
                @endif
            </div>
        </x-slot:extra>
    @endif
</x-confirm-modal>

@php
    $replaceTarget = $this->replacingId === null ? null : App\Models\Media::query()->withCount('usages')->find($this->replacingId);
    $restoreTarget = $this->restoringId === null ? null : App\Models\Media::query()->withCount('usages')->find($this->restoringId);
@endphp

<x-confirm-modal
    wire:model.self="showReplaceModal"
    wire:close="cancelPending"
    :heading="__('media.replace.heading', ['name' => $replaceTarget?->name])"
    :confirm-label="__('media.replace.confirm')"
    confirm-action="replaceConfirmed"
>
    {{ __('media.replace.body', ['new' => $this->replacement?->getClientOriginalName()]) }}
    @if ($replaceTarget)
        {{ trans_choice('media.crop.confirm.body', $replaceTarget->usages_count) }}
    @endif
</x-confirm-modal>

<x-confirm-modal
    wire:model.self="showRestoreModal"
    wire:close="cancelPending"
    :heading="__('media.restore.heading', ['name' => $restoreTarget?->name])"
    :confirm-label="__('media.restore.confirm')"
    confirm-action="restoreConfirmed"
>
    {{ __('media.restore.body') }}
    @if ($restoreTarget)
        {{ trans_choice('media.crop.confirm.body', $restoreTarget->usages_count) }}
    @endif
</x-confirm-modal>

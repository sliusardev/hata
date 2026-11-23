{{--
    Dialogs of the media library page: discard unsaved details, delete files, the folder name form, delete a folder.
    Rendered inside the page component, so it reads the component's state directly.
--}}
<x-hata::confirm-modal
    wire:model.self="showDiscardModal"
    :heading="__('media.details.unsaved.heading')"
    :confirm-label="__('media.details.unsaved.discard')"
    confirm-action="discardChanges"
>
    {{ __('media.details.unsaved.body', ['name' => $this->selectedMedia?->name]) }}
</x-hata::confirm-modal>

@php
    $deleting = $this->deletingMedia;
    $deletingUsages = $deleting->flatMap->usages;
    $deletingUsedFiles = $deleting->filter(fn ($media) => $media->usages->isNotEmpty())->count();
@endphp

<x-hata::confirm-modal
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
                            <li wire:key="deleting-usage-{{ $usage->id }}">
                                @if (($usageUrl = $usage->url(auth()->user())) !== null)
                                    <a href="{{ $usageUrl }}" class="underline underline-offset-2" wire:navigate>{{ $usage->label() }}</a>
                                @else
                                    {{ $usage->label() }}
                                @endif
                            </li>
                        @endforeach
                    </ul>
                @endif
                <div>{{ __('media.delete.used_warning') }}</div>
            </div>
        </x-slot:extra>
    @endif
</x-hata::confirm-modal>

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

<flux:modal wire:model.self="showMoveFolderModal" wire:close="cancelPending" class="max-w-md md:min-w-md">
    @php
        $movingFolder = $this->movingFolder;
        $moveTargets = $this->moveFolderTargets;
        $currentParentId = $movingFolder?->parent_id;
        $currentParentName = collect($moveTargets)->firstWhere('id', $currentParentId)['name'] ?? null;
        $pickerRowClasses = 'flex h-9 w-full cursor-pointer select-none items-center gap-2 rounded-lg px-2.5 text-start text-sm text-zinc-700 hover:bg-zinc-800/5 has-[:focus-visible]:ring-2 has-[:focus-visible]:ring-accent has-[:checked]:bg-accent/10 has-[:checked]:font-medium has-[:checked]:text-zinc-900 has-[:checked]:ring-1 has-[:checked]:ring-accent has-[:disabled]:cursor-not-allowed has-[:disabled]:opacity-60 has-[:disabled]:hover:bg-transparent dark:text-zinc-300 dark:hover:bg-white/10 dark:has-[:checked]:text-white';
    @endphp

    <form
        wire:submit="moveFolderConfirmed"
        class="space-y-4"
        wire:key="move-folder-picker-{{ $movingFolder?->id }}"
        x-data="{
            rows: @js($moveTargets),
            query: '',
            byId() { return Object.fromEntries(this.rows.map((row) => [row.id, row])) },
            ancestors(id) {
                const byId = this.byId();
                const result = [];
                let parent = byId[id]?.parent;
                while (parent && byId[parent] && result.length < 1000) {
                    result.push(parent);
                    parent = byId[parent].parent;
                }
                return result;
            },
            get visible() {
                const needle = this.query.trim().toLocaleLowerCase();
                if (needle === '') { return null }
                const set = new Set();
                this.rows.forEach((row) => {
                    if (row.name.toLocaleLowerCase().includes(needle)) {
                        set.add(row.id);
                        this.ancestors(row.id).forEach((id) => set.add(id));
                    }
                });
                return set;
            },
            shows(id) { const set = this.visible; return set === null || set.has(id) },
            get topShown() { return this.query.trim() === '' },
            get noMatches() { const set = this.visible; return set !== null && set.size === 0 },
            choose(value) { $wire.set('moveFolderTarget', value, false).then(() => $wire.moveFolderConfirmed()) },
        }"
    >
        <div class="space-y-1">
            <flux:heading size="lg">{{ __('media.folders.move_heading', ['name' => $movingFolder?->name]) }}</flux:heading>
            <flux:text>{{ __('media.folders.move_current', ['name' => $currentParentName ?? __('media.folders.top_level')]) }}</flux:text>
        </div>

        @if (count($moveTargets) > 8)
            <flux:input icon="magnifying-glass" clearable x-model="query" x-on:keydown.enter.prevent :placeholder="__('media.folders.move_search')" :aria-label="__('media.folders.move_search')" data-test="media-folder-move-search" />
        @endif

        <div
            role="radiogroup"
            aria-label="{{ __('media.folders.move_target') }}"
            class="max-h-80 space-y-0.5 overflow-y-auto rounded-lg border border-zinc-200 p-1 dark:border-white/10"
            data-test="media-folder-move-list"
        >
            <label x-show="topShown" class="{{ $pickerRowClasses }}" x-on:dblclick="@if ($currentParentId !== null) choose('top') @endif" data-test="media-folder-move-top">
                <input type="radio" name="move-folder-target" class="sr-only" wire:model="moveFolderTarget" value="top" @disabled($currentParentId === null) />
                <flux:icon name="home" variant="outline" class="size-4 shrink-0 text-zinc-400" />
                <span class="flex-1 truncate">{{ __('media.folders.top_level') }}</span>
                @if ($currentParentId === null)
                    <span class="text-xs text-zinc-400">{{ __('media.folders.move_is_current') }}</span>
                @endif
                <flux:icon name="check" variant="micro" class="hidden size-4 shrink-0 text-accent-content in-[label:has(:checked)]:block" />
            </label>

            @foreach ($moveTargets as $target)
                @php
                    $isCurrentParent = $target['id'] === $currentParentId;
                @endphp
                <label
                    wire:key="move-folder-{{ $target['id'] }}"
                    x-show="shows({{ $target['id'] }})"
                    class="{{ $pickerRowClasses }}"
                    style="padding-inline-start: calc(0.625rem + {{ min($target['depth'], 4) * 0.75 }}rem)"
                    @unless ($isCurrentParent) x-on:dblclick="choose('{{ $target['id'] }}')" @endunless
                    data-test="media-folder-move-{{ $target['id'] }}"
                >
                    <input type="radio" name="move-folder-target" class="sr-only" wire:model="moveFolderTarget" value="{{ $target['id'] }}" @disabled($isCurrentParent) />
                    <flux:icon name="folder" variant="outline" class="size-4 shrink-0 text-zinc-400" />
                    <span class="flex-1 truncate" title="{{ $target['name'] }}">{{ $target['name'] }}</span>
                    @if ($isCurrentParent)
                        <span class="text-xs text-zinc-400">{{ __('media.folders.move_is_current') }}</span>
                    @endif
                    <flux:icon name="check" variant="micro" class="hidden size-4 shrink-0 text-accent-content in-[label:has(:checked)]:block" />
                </label>
            @endforeach

            <p x-show="noMatches" x-cloak class="px-2.5 py-3 text-sm text-zinc-500">{{ __('media.folders.move_no_match') }}</p>
        </div>

        <div class="flex justify-end gap-3">
            <flux:modal.close>
                <flux:button variant="ghost">{{ __('common.actions.cancel') }}</flux:button>
            </flux:modal.close>
            <flux:button type="submit" variant="primary" x-bind:disabled="$wire.moveFolderTarget === ''" data-test="media-folder-move-confirm">{{ __('media.folders.move_confirm') }}</flux:button>
        </div>
    </form>
</flux:modal>

@php
    $deletingFolder = $this->deletingFolder;
    $summary = $this->folderDeletionSummary;
    $needsName = $summary !== null && $summary['files'] > 10;
@endphp

<x-hata::confirm-modal
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
</x-hata::confirm-modal>

@php
    $replaceTarget = $this->replacingId === null ? null : Hata\Models\Media::query()->withCount('usages')->find($this->replacingId);
    $restoreTarget = $this->restoringId === null ? null : Hata\Models\Media::query()->withCount('usages')->find($this->restoringId);
@endphp

<x-hata::confirm-modal
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
</x-hata::confirm-modal>

<x-hata::confirm-modal
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
</x-hata::confirm-modal>

{{--
    List view of the media library: flux:table, 25 per page, sortable by name, size and date.
    Expects: $media (paginator), $sortBy, $sortDirection, $selected (ids picked for bulk actions),
    $selectedId (the file open in the details panel),
    $canMove (rows can be dragged onto a folder in the tree).
--}}
@php
    $checkboxClasses = 'size-4 rounded border-zinc-300 accent-zinc-800 dark:border-white/20 dark:accent-white';
    $pageIds = $media->getCollection()->modelKeys();
    $allOnPage = $pageIds !== [] && array_diff($pageIds, $selected) === [];
@endphp
<flux:table :paginate="$media">
    <flux:table.columns>
        <flux:table.column class="w-10">
            {{-- Re-keyed on change, so the browser's own checked state never drifts from the server's. --}}
            <input
                type="checkbox"
                wire:key="media-select-page-{{ $allOnPage ? 'on' : 'off' }}"
                wire:click="togglePageSelection(true)"
                @checked($allOnPage)
                class="{{ $checkboxClasses }}"
                aria-label="{{ __('media.bulk.select_page') }}"
                data-test="media-select-page"
            />
        </flux:table.column>
        <flux:table.column sortable :sorted="$sortBy === 'name'" :direction="$sortDirection" wire:click="sort('name', true)">{{ __('media.index.columns.file') }}</flux:table.column>
        <flux:table.column class="max-md:hidden">{{ __('media.index.columns.type') }}</flux:table.column>
        <flux:table.column sortable :sorted="$sortBy === 'size'" :direction="$sortDirection" wire:click="sort('size', true)">{{ __('media.index.columns.size') }}</flux:table.column>
        <flux:table.column class="max-lg:hidden">{{ __('media.index.columns.usage') }}</flux:table.column>
        <flux:table.column sortable :sorted="$sortBy === 'created_at'" :direction="$sortDirection" wire:click="sort('created_at', true)" class="max-lg:hidden">{{ __('media.index.columns.uploaded') }}</flux:table.column>
        <flux:table.column align="end"><span class="sr-only">{{ __('media.index.columns.actions') }}</span></flux:table.column>
    </flux:table.columns>

    <flux:table.rows>
        @foreach ($media as $item)
            @php
                $alt = $item->altFor(app()->getLocale());
                $isActive = $selectedId === $item->id || in_array($item->id, $selected, true);
            @endphp
            <flux:table.row
                :key="$item->id"
                :class="$isActive ? 'bg-zinc-100 dark:bg-white/10' : null"
                :data-active="$isActive ? 'true' : 'false'"
                :draggable="$canMove ? 'true' : null"
                :x-on:dragstart="$canMove ? 'mediaDragStart($event, '.$item->id.', $wire)' : null"
            >
                <flux:table.cell>
                    <input
                        type="checkbox"
                        wire:model.live="selected"
                        value="{{ $item->id }}"
                        x-on:click="if ($event.shiftKey) { $event.preventDefault(); $wire.pick({{ $item->id }}, 'range') }"
                        class="{{ $checkboxClasses }}"
                        aria-label="{{ __('media.bulk.select_file', ['name' => $item->name]) }}"
                    />
                </flux:table.cell>
                <flux:table.cell>
                    <div class="flex items-center gap-3">
                        <div class="flex size-10 shrink-0 items-center justify-center overflow-hidden rounded-md bg-zinc-100 ring-1 ring-black/5 ring-inset dark:bg-white/5 dark:ring-white/10">
                            @if ($thumbUrl = $item->thumbUrl())
                                <img src="{{ $thumbUrl }}" alt="" loading="lazy" draggable="false" class="size-full object-cover" />
                            @else
                                <span class="text-[10px] font-semibold tracking-wide text-zinc-500 dark:text-zinc-400">{{ $item->extension() }}</span>
                            @endif
                        </div>
                        <div class="min-w-0">
                            <button type="button" wire:click="select({{ $item->id }}, true)" class="block max-w-full truncate text-start font-medium text-zinc-800 hover:underline dark:text-white">{{ $item->name }}</button>
                            @if ($alt !== '')
                                <div class="truncate text-xs text-zinc-500 dark:text-zinc-400">{{ $alt }}</div>
                            @elseif ($item->isImage())
                                <div class="text-xs text-amber-600 dark:text-amber-400">{{ __('media.index.no_alt') }}</div>
                            @endif
                        </div>
                    </div>
                </flux:table.cell>
                <flux:table.cell class="max-md:hidden">{{ $item->extension() }}</flux:table.cell>
                <flux:table.cell class="whitespace-nowrap">{{ $item->humanSize() }}</flux:table.cell>
                <flux:table.cell class="max-lg:hidden">
                    @if ($item->usages_count === 0)
                        <flux:badge size="sm" color="amber">{{ __('media.index.nowhere') }}</flux:badge>
                    @else
                        <flux:badge size="sm" color="zinc">{{ trans_choice('media.index.used_count', $item->usages_count) }}</flux:badge>
                    @endif
                </flux:table.cell>
                <flux:table.cell class="whitespace-nowrap max-lg:hidden">{{ $item->created_at?->tz(config('admin.timezone'))->isoFormat('LL') }}</flux:table.cell>
                <flux:table.cell align="end">
                    <x-admin.row-actions data-test="media-actions-{{ $item->id }}">
                        <flux:menu.item icon="information-circle" wire:click="select({{ $item->id }}, true)">{{ __('media.details.heading') }}</flux:menu.item>
                        <flux:menu.item icon="arrow-top-right-on-square" :href="$item->url()" target="_blank">{{ __('media.index.open') }}</flux:menu.item>
                        <flux:menu.item icon="arrow-down-tray" :href="$item->appUrl().'?download=1'">{{ __('media.details.download') }}</flux:menu.item>
                        @can('delete', $item)
                            <flux:menu.item icon="trash" variant="danger" wire:click="confirmDelete({{ $item->id }})">{{ __('common.actions.delete') }}</flux:menu.item>
                        @endcan
                    </x-admin.row-actions>
                </flux:table.cell>
            </flux:table.row>
        @endforeach
    </flux:table.rows>
</flux:table>

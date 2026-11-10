{{--
    List view of the media library: flux:table, 25 per page, sortable by name, size and date.
    Expects: $media (paginator), $sortBy, $sortDirection.
--}}
<flux:table :paginate="$media">
    <flux:table.columns>
        {{-- Task 11: the "select all on this page" checkbox column goes here. --}}
        <flux:table.column sortable :sorted="$sortBy === 'name'" :direction="$sortDirection" wire:click="sort('name')">{{ __('media.index.columns.file') }}</flux:table.column>
        <flux:table.column class="max-md:hidden">{{ __('media.index.columns.type') }}</flux:table.column>
        <flux:table.column sortable :sorted="$sortBy === 'size'" :direction="$sortDirection" wire:click="sort('size')">{{ __('media.index.columns.size') }}</flux:table.column>
        <flux:table.column class="max-lg:hidden">{{ __('media.index.columns.usage') }}</flux:table.column>
        <flux:table.column sortable :sorted="$sortBy === 'created_at'" :direction="$sortDirection" wire:click="sort('created_at')" class="max-lg:hidden">{{ __('media.index.columns.uploaded') }}</flux:table.column>
        <flux:table.column align="end"><span class="sr-only">{{ __('media.index.columns.actions') }}</span></flux:table.column>
    </flux:table.columns>

    <flux:table.rows>
        @foreach ($media as $item)
            @php $alt = $item->altFor(app()->getLocale()); @endphp
            <flux:table.row :key="$item->id">
                <flux:table.cell>
                    <div class="flex items-center gap-3">
                        <div class="flex size-10 shrink-0 items-center justify-center overflow-hidden rounded-md bg-zinc-100 ring-1 ring-black/5 ring-inset dark:bg-white/5 dark:ring-white/10">
                            @if ($thumbUrl = $item->thumbUrl())
                                <img src="{{ $thumbUrl }}" alt="" loading="lazy" class="size-full object-cover" />
                            @else
                                <span class="text-[10px] font-semibold tracking-wide text-zinc-500 dark:text-zinc-400">{{ $item->extension() }}</span>
                            @endif
                        </div>
                        <div class="min-w-0">
                            <div class="truncate font-medium text-zinc-800 dark:text-white">{{ $item->name }}</div>
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
                    {{-- Task 10 adds details, download and delete to this menu. --}}
                    <x-admin.row-actions data-test="media-actions-{{ $item->id }}">
                        <flux:menu.item icon="arrow-top-right-on-square" :href="$item->url()" target="_blank">{{ __('media.index.open') }}</flux:menu.item>
                    </x-admin.row-actions>
                </flux:table.cell>
            </flux:table.row>
        @endforeach
    </flux:table.rows>
</flux:table>

<?php

use App\Livewire\Admin\EntryListPage;

new class extends EntryListPage {
    public function render(): mixed
    {
        return $this->view()->title($this->contentType->pluralLabel());
    }
}; ?>

@php
    $contentType = $this->contentType;
    $counts = $this->counts;
    $abilities = $this->abilities;
    $inTrash = $tab === 'trash';
    // Only the full tree is indented: a status tab or a filter can hide a row's parent.
    $indent = $this->listQuery->isTree() && $tab === 'all' && trim($search) === '' && $missing === '';
    $showTranslations = $this->multilingual && ! $inTrash;
    $activeCodes = app(App\Support\Localization\ContentLocales::class)->activeCodes();
    $timezone = config('admin.timezone');
    $trashDays = (int) config('content.trash_days');
    $selectable = $inTrash ? ($abilities['edit'] || $abilities['delete']) : $abilities['delete'];
    $checkbox = 'size-4 cursor-pointer rounded accent-zinc-800 dark:accent-white';
@endphp

<section
    class="w-full space-y-6"
    x-data="{
        pageIds() { return [...$el.querySelectorAll('[data-row-check]')].map((box) => box.value) },
        allPicked() { const ids = this.pageIds(); return ids.length > 0 && ids.every((id) => $wire.selected.includes(id)) },
        pickAll(on) { $wire.selected = on ? this.pageIds() : [] },
    }"
>
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <flux:heading size="xl" level="1">{{ $contentType->pluralLabel() }}</flux:heading>
            <flux:subheading>{{ $contentType->description() }}</flux:subheading>
        </div>

        @if ($abilities['create'])
            <flux:button variant="primary" icon="plus" :href="route('admin.content.create', ['type' => $type])" wire:navigate data-test="create-entry">
                {{ $contentType->createLabel() }}
            </flux:button>
        @endif
    </div>

    {{-- The border is on a wrapper: a negative margin on the nav itself would replace the section's `space-y` gap
         (Tailwind 4 sets it as the bottom margin), and one on the buttons overflows the scrolling nav by a pixel,
         which shows a vertical scrollbar. --}}
    <div class="border-b border-zinc-200 dark:border-zinc-700">
    <nav class="-mb-px flex gap-6 overflow-x-auto text-sm" aria-label="{{ $contentType->pluralLabel() }}">
        @foreach (App\Support\Content\EntryListQuery::TABS as $tabKey)
            <button
                type="button"
                wire:key="tab-{{ $tabKey }}"
                wire:click="$set('tab', '{{ $tabKey }}')"
                @class([
                    'flex shrink-0 items-center gap-1.5 border-b-2 pb-3 whitespace-nowrap transition-colors',
                    'ms-auto' => $tabKey === 'trash',
                    'border-zinc-800 font-medium text-zinc-900 dark:border-white dark:text-white' => $tab === $tabKey,
                    'border-transparent text-zinc-500 hover:text-zinc-800 dark:text-zinc-400 dark:hover:text-white' => $tab !== $tabKey,
                ])
                @if ($tab === $tabKey) aria-current="page" @endif
                data-test="tab-{{ $tabKey }}"
            >
                @if ($tabKey === 'trash')
                    <flux:icon.trash variant="micro" class="text-zinc-400" />
                @endif
                {{ __('content.list.tabs.'.$tabKey) }}
                <span class="text-zinc-400 tabular-nums dark:text-zinc-500">{{ $counts[$tabKey] }}</span>
            </button>
        @endforeach
    </nav>
    </div>

    <div class="flex flex-col gap-3 sm:flex-row sm:flex-wrap sm:items-center">
        <flux:input
            wire:model.live.debounce.300ms="search"
            icon="magnifying-glass"
            :placeholder="__('content.list.search')"
            :aria-label="__('content.list.search')"
            :maxlength="$this::SEARCH_MAX"
            clearable
            class="sm:max-w-xs"
        />

        @if ($this->multilingual)
            <flux:select wire:model.live="locale" :aria-label="__('content.list.locale')" class="sm:max-w-52" data-test="list-locale">
                <flux:select.option value="">{{ __('content.list.locale_all') }}</flux:select.option>
                @foreach ($this->languages as $language)
                    <flux:select.option :value="$language->code" wire:key="locale-{{ $language->code }}">
                        {{ __('content.list.locale_option', ['name' => $language->native]) }}{{ in_array($language->code, $activeCodes, true) ? '' : ' ('.__('content.list.off').')' }}
                    </flux:select.option>
                @endforeach
            </flux:select>

            <flux:select wire:model.live="missing" :aria-label="__('content.list.missing')" class="sm:max-w-60" data-test="list-missing">
                <flux:select.option value="">{{ __('content.list.missing_all') }}</flux:select.option>
                @foreach ($this->languages as $language)
                    @continue($language->code === $locale)
                    <flux:select.option :value="$language->code" wire:key="missing-{{ $language->code }}">
                        {{ __('content.list.missing_option', ['name' => $language->native]) }}
                    </flux:select.option>
                @endforeach
            </flux:select>
        @endif

        @if ($inTrash && $abilities['delete'] && $counts['trash'] > 0)
            <flux:button variant="danger" size="sm" icon="trash" x-on:click="$wire.confirmBulk('empty')" class="sm:ms-auto" data-test="empty-trash">
                {{ __('content.list.bulk.empty_trash') }}
            </flux:button>
        @endif

        @if ($contentType->hierarchical() && $sort !== '' && ! $inTrash)
            <flux:button variant="ghost" size="sm" icon="bars-3-bottom-left" wire:click="resetSort" class="sm:ms-auto">
                {{ __('content.list.tree_order') }}
            </flux:button>
        @endif
    </div>

    @if ($this->entries->isEmpty())
        @if ($this->isFiltered())
            <x-admin.empty-state icon="magnifying-glass" :heading="__('content.list.no_results.title')" :description="__('content.list.no_results.description')">
                <flux:button size="sm" class="mt-4" wire:click="clearFilters">{{ __('content.list.no_results.clear') }}</flux:button>
            </x-admin.empty-state>
        @elseif ($inTrash)
            <x-admin.empty-state icon="trash" :heading="__('content.list.empty_trash.title')" :description="trans_choice('content.list.empty_trash.description', $trashDays)" />
        @elseif ($tab === 'all' && $counts['all'] === 0)
            <x-admin.empty-state :icon="$contentType->icon()" :heading="__('content.list.empty.title')" :description="__('content.list.empty.description')">
                @if ($abilities['create'])
                    <flux:button variant="primary" size="sm" icon="plus" class="mt-4" :href="route('admin.content.create', ['type' => $type])" wire:navigate>
                        {{ $contentType->createLabel() }}
                    </flux:button>
                @endif
            </x-admin.empty-state>
        @else
            <x-admin.empty-state icon="funnel" :heading="__('content.list.empty_tab.title')" :description="__('content.list.empty_tab.description')" />
        @endif
    @else
        @if ($selectable)
            <div x-cloak x-show="$wire.selected.length > 0" class="sticky top-2 z-10 flex flex-wrap items-center gap-3 rounded-xl border border-zinc-200 bg-white px-4 py-2.5 shadow-sm dark:border-zinc-700 dark:bg-zinc-900" data-test="bulk-bar">
                <span class="text-sm font-medium text-zinc-900 dark:text-white">{{ __('content.list.bulk.selected') }} <span class="tabular-nums" x-text="$wire.selected.length"></span></span>

                <div class="flex flex-wrap items-center gap-2 sm:ms-auto">
                    @if ($inTrash)
                        @if ($abilities['edit'])
                            <flux:button size="sm" icon="arrow-uturn-left" x-on:click="$wire.restoreSelected()" data-test="bulk-restore">{{ __('content.list.actions.restore') }}</flux:button>
                        @endif
                        @if ($abilities['delete'])
                            <flux:button size="sm" variant="danger" icon="trash" x-on:click="$wire.confirmBulk('delete')" data-test="bulk-delete">{{ __('content.list.actions.delete') }}</flux:button>
                        @endif
                    @else
                        <flux:button size="sm" variant="danger" icon="trash" x-on:click="$wire.confirmBulk('trash')" data-test="bulk-trash">{{ __('content.list.actions.trash') }}</flux:button>
                    @endif
                    <flux:button size="sm" variant="ghost" x-on:click="$wire.selected = []">{{ __('content.list.bulk.clear') }}</flux:button>
                </div>
            </div>
        @endif

        <div class="rounded-xl border border-zinc-200 bg-white px-4 shadow-xs dark:border-zinc-700 dark:bg-zinc-900/40">
            <flux:table :paginate="$this->entries" pagination:class="pb-3">
                <flux:table.columns>
                    @if ($selectable)
                        <flux:table.column class="w-8">
                            <input type="checkbox" class="{{ $checkbox }}" x-bind:checked="allPicked()" x-on:change="pickAll($event.target.checked)" aria-label="{{ __('content.list.bulk.select_all') }}" data-test="select-all" />
                        </flux:table.column>
                    @endif
                    @if ($inTrash)
                        <flux:table.column>{{ __('content.list.columns.title') }}</flux:table.column>
                    @else
                        <flux:table.column sortable :sorted="$this->sortColumn() === 'title'" :direction="$this->sortDirection()" wire:click="sort('title')">{{ __('content.list.columns.title') }}</flux:table.column>
                    @endif
                    <flux:table.column class="max-lg:hidden">{{ __('content.list.columns.author') }}</flux:table.column>
                    @if ($inTrash)
                        <flux:table.column class="max-lg:hidden">{{ __('content.list.columns.removal') }}</flux:table.column>
                    @else
                        <flux:table.column sortable :sorted="$this->sortColumn() === 'date'" :direction="$this->sortDirection()" wire:click="sort('date')" class="max-lg:hidden">{{ __('content.list.columns.date') }}</flux:table.column>
                    @endif
                    <flux:table.column align="end"><span class="sr-only">{{ __('content.list.columns.actions') }}</span></flux:table.column>
                </flux:table.columns>

                <flux:table.rows>
                    @foreach ($this->entries as $entry)
                        @php
                            $state = $entry->state();
                            $depth = $indent ? $entry->depth() : 0;
                            $editUrl = route('admin.content.edit', ['type' => $type, 'entry' => $entry->id]);
                            $thumb = $entry->image?->thumbUrl();
                        @endphp
                        <flux:table.row :key="$entry->id">
                            @if ($selectable)
                                <flux:table.cell class="w-8">
                                    <input type="checkbox" class="{{ $checkbox }}" value="{{ $entry->id }}" x-model="$wire.selected" data-row-check aria-label="{{ __('content.list.bulk.select', ['title' => $entry->title]) }}" data-test="select-entry-{{ $entry->id }}" />
                                </flux:table.cell>
                            @endif
                            <flux:table.cell>
                                <div data-depth="{{ $depth }}" data-test="entry-row-{{ $entry->id }}" class="flex min-w-0 items-center gap-3" @if ($depth > 0) style="padding-inline-start: {{ min($depth, 6) * 1.5 - 1.5 }}rem" @endif>
                                    @if ($depth > 0)
                                        <flux:icon.arrow-turn-down-right variant="micro" class="shrink-0 text-zinc-300 rtl:-scale-x-100 dark:text-zinc-600" />
                                    @endif
                                    @if ($thumb)
                                        <img src="{{ $thumb }}" alt="" loading="lazy" decoding="async" class="size-10 shrink-0 rounded-md bg-zinc-100 object-cover dark:bg-zinc-700" />
                                    @else
                                        <div class="flex size-10 shrink-0 items-center justify-center rounded-md bg-zinc-100 dark:bg-zinc-700/60" title="{{ __('content.list.no_image') }}">
                                            <flux:icon.photo variant="micro" class="text-zinc-300 dark:text-zinc-500" />
                                        </div>
                                    @endif
                                    <div class="min-w-0 max-w-[14rem] sm:max-w-sm xl:max-w-md">
                                        <div class="flex min-w-0 items-center gap-2">
                                            @if ($this->multilingual && $inTrash)
                                                <span @class(['shrink-0 rounded bg-zinc-100 px-1.5 py-0.5 text-[11px] font-semibold text-zinc-700 uppercase dark:bg-white/10 dark:text-zinc-200', 'opacity-60' => ! in_array($entry->locale, $activeCodes, true)]) data-test="entry-language-{{ $entry->id }}">{{ $entry->locale }}</span>
                                            @endif
                                            <a href="{{ $editUrl }}" wire:navigate class="truncate font-medium text-zinc-900 hover:underline dark:text-white" data-test="edit-entry-link-{{ $entry->id }}">{{ $entry->title }}</a>
                                            @if (! $inTrash && $state === App\Enums\EntryState::Draft)
                                                <flux:badge size="sm" color="zinc" class="shrink-0">{{ __('content.list.state.draft') }}</flux:badge>
                                            @elseif (! $inTrash && $state === App\Enums\EntryState::Scheduled)
                                                <flux:badge size="sm" color="sky" class="shrink-0">{{ __('content.list.state.scheduled') }}</flux:badge>
                                            @endif
                                        </div>
                                        <div class="truncate text-xs text-zinc-500 dark:text-zinc-400">{{ $entry->url(false) }}</div>
                                        @if ($showTranslations)
                                            <div class="mt-1.5" data-test="translations-{{ $entry->id }}">
                                                @include('partials.admin.content.translation-chips', [
                                                    'type' => $type,
                                                    'languages' => $this->languages,
                                                    'current' => $entry->locale,
                                                    'records' => $entry->groupEntries,
                                                    'canCreate' => $abilities['create'],
                                                    'fromId' => $entry->id,
                                                    'activeCodes' => $activeCodes,
                                                ])
                                            </div>
                                        @endif
                                        @if ($inTrash)
                                            <div class="text-xs text-zinc-500 lg:hidden dark:text-zinc-400">{{ __('content.list.removed_on', ['date' => $entry->deleted_at->copy()->addDays($trashDays)->tz($timezone)->isoFormat('LL')]) }}</div>
                                        @endif
                                    </div>
                                </div>
                            </flux:table.cell>

                            <flux:table.cell class="max-lg:hidden">{{ $entry->author?->name ?? '—' }}</flux:table.cell>

                            <flux:table.cell class="max-lg:hidden">
                                @if ($inTrash)
                                    {{ __('content.list.removed_on', ['date' => $entry->deleted_at->copy()->addDays($trashDays)->tz($timezone)->isoFormat('LL')]) }}
                                @else
                                    {{ ($entry->published_at ?? $entry->created_at)?->tz($timezone)->isoFormat('LL') }}
                                @endif
                            </flux:table.cell>

                            <flux:table.cell align="end">
                                <x-admin.row-actions data-test="entry-actions-{{ $entry->id }}">
                                    @if ($inTrash)
                                        @if ($abilities['edit'])
                                            <flux:menu.item icon="arrow-uturn-left" wire:click="restore({{ $entry->id }})" data-test="restore-entry-{{ $entry->id }}">{{ __('content.list.actions.restore') }}</flux:menu.item>
                                        @endif
                                        @if ($abilities['delete'])
                                            <flux:menu.item variant="danger" icon="trash" wire:click="confirmDelete({{ $entry->id }})" data-test="delete-entry-{{ $entry->id }}">{{ __('content.list.actions.delete') }}</flux:menu.item>
                                        @endif
                                    @else
                                        @if ($abilities['edit'])
                                            <flux:menu.item icon="pencil-square" :href="$editUrl" wire:navigate data-test="edit-entry-menu-{{ $entry->id }}">{{ __('common.actions.edit') }}</flux:menu.item>
                                        @endif
                                        @if ($entry->isPublic())
                                            @if (in_array($entry->locale, $activeCodes, true))
                                                <flux:menu.item icon="arrow-top-right-on-square" :href="$entry->url()" target="_blank" data-test="view-entry-{{ $entry->id }}">{{ __('content.list.actions.view') }}</flux:menu.item>
                                            @else
                                                <p class="max-w-60 px-2 py-1.5 text-xs text-zinc-500 dark:text-zinc-400" data-test="language-off-note-{{ $entry->id }}">{{ __('content.form.language_off') }}</p>
                                            @endif
                                        @endif
                                        @if ($abilities['delete'])
                                            <flux:menu.separator />
                                            <flux:menu.item variant="danger" icon="trash" wire:click="confirmTrash({{ $entry->id }})" data-test="trash-entry-{{ $entry->id }}">{{ __('content.list.actions.trash') }}</flux:menu.item>
                                        @endif
                                    @endif
                                </x-admin.row-actions>
                            </flux:table.cell>
                        </flux:table.row>
                    @endforeach
                </flux:table.rows>
            </flux:table>
        </div>
    @endif

    <x-confirm-modal
        wire:model.self="showTrashModal"
        :heading="__('content.list.trash.title')"
        :confirm-label="__('content.list.trash.submit')"
        confirm-action="trash"
    >
        @if ($trashingChildren > 0)
            {{ trans_choice('content.list.trash.confirm_children', $trashingChildren, ['title' => $this->trashingEntry?->title]) }}
        @else
            {{ trans_choice('content.list.trash.confirm', $trashDays, ['title' => $this->trashingEntry?->title]) }}
        @endif
    </x-confirm-modal>

    <x-confirm-modal
        wire:model.self="showDeleteModal"
        :heading="__('content.list.delete.title')"
        :confirm-label="__('content.list.delete.submit')"
        confirm-action="deleteForGood"
    >
        @if ($deletingChildren > 0)
            {{ trans_choice('content.list.delete.confirm_children', $deletingChildren, ['title' => $this->deletingEntry?->title]) }}
        @else
            {{ __('content.list.delete.confirm', ['title' => $this->deletingEntry?->title]) }}
        @endif
    </x-confirm-modal>

    <x-confirm-modal
        wire:model.self="showBulkModal"
        :heading="$bulkAction === 'trash' ? __('content.list.trash.title') : ($bulkAction === 'empty' ? __('content.list.bulk.empty_title') : __('content.list.delete.title'))"
        :confirm-label="$bulkAction === 'trash' ? __('content.list.trash.submit') : ($bulkAction === 'empty' ? __('content.list.bulk.empty_trash') : __('content.list.delete.submit'))"
        confirm-action="runBulk"
        data-test="bulk-modal"
    >
        @if ($bulkAction === 'trash')
            {{ trans_choice('content.list.bulk.trash_confirm', $bulkCount, ['days' => $trashDays]) }}
        @elseif ($bulkAction === 'empty')
            {{ trans_choice('content.list.bulk.empty_confirm', $bulkCount) }}
        @else
            {{ trans_choice('content.list.bulk.delete_confirm', $bulkCount) }}
        @endif
    </x-confirm-modal>
</section>

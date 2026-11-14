<?php

use App\Actions\Content\DeleteEntry;
use App\Actions\Content\PruneEntryTrash;
use App\Actions\Content\RestoreEntry;
use App\Actions\Content\TrashEntry;
use App\Enums\EntryState;
use App\Exceptions\ProtectedActionException;
use App\Models\Entry;
use App\Support\Content\ContentType;
use App\Support\Content\ContentTypes;
use App\Support\Content\EntryListQuery;
use App\Support\Localization\ContentLocales;
use App\Support\Localization\Language;
use Flux\Flux;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new class extends Component {
    use WithPagination;

    /**
     * The session key that remembers the language the list was last shown in.
     */
    public const string LOCALE_SESSION_KEY = 'content.list_locale';

    /**
     * The longest search term used; a longer one (a pasted text, a crafted query string) is cut.
     */
    public const int SEARCH_MAX = 200;

    #[Locked]
    public string $type = '';

    #[Url(except: 'all')]
    public string $tab = 'all';

    #[Url(as: 'q', except: '')]
    public string $search = '';

    #[Url(except: '')]
    public string $locale = '';

    #[Url(except: '')]
    public string $missing = '';

    /**
     * Empty means the type's default: tree order for a hierarchical type, newest first otherwise.
     */
    #[Url(except: '')]
    public string $sort = '';

    #[Url(except: '')]
    public string $dir = '';

    #[Locked]
    public ?int $trashingId = null;

    #[Locked]
    public int $trashingChildren = 0;

    public bool $showTrashModal = false;

    #[Locked]
    public ?int $deletingId = null;

    #[Locked]
    public int $deletingChildren = 0;

    public bool $showDeleteModal = false;

    public function mount(string $type): void
    {
        $this->authorize('viewAny', [Entry::class, $type]);

        $this->type = $type;
        $this->tab = in_array($this->tab, EntryListQuery::TABS, true) ? $this->tab : 'all';
        $this->search = mb_substr($this->search, 0, self::SEARCH_MAX);
        $this->locale = $this->validLocale($this->locale !== '' ? $this->locale : (string) session(self::LOCALE_SESSION_KEY));
        $this->missing = $this->validMissing($this->missing);
        session()->put(self::LOCALE_SESSION_KEY, $this->locale);

        // At most once an hour per type, after the response is sent: records whose time in the trash is over.
        if (Cache::add("content.prune_trash.{$type}", true, now()->addHour())) {
            defer(function (): void {
                try {
                    app(PruneEntryTrash::class)->handle();
                } catch (Throwable $exception) {
                    report($exception);
                }
            });
        }
    }

    public function render(): mixed
    {
        return $this->view()->title($this->contentType->pluralLabel());
    }

    public function updatedTab(): void
    {
        $this->tab = in_array($this->tab, EntryListQuery::TABS, true) ? $this->tab : 'all';
        $this->resetPage();
    }

    public function updatedSearch(): void
    {
        $this->search = mb_substr($this->search, 0, self::SEARCH_MAX);
        $this->resetPage();
    }

    public function updatedLocale(): void
    {
        $this->locale = $this->validLocale($this->locale);
        $this->missing = $this->validMissing($this->missing);
        session()->put(self::LOCALE_SESSION_KEY, $this->locale);
        $this->resetPage();
    }

    public function updatedMissing(): void
    {
        $this->missing = $this->validMissing($this->missing);
        $this->resetPage();
    }

    /**
     * Title and date toggle between ascending and descending; on a hierarchical type a third click goes back to
     * the tree order.
     */
    public function sort(string $column): void
    {
        if (! in_array($column, ['title', 'date'], true)) {
            return;
        }

        $first = $column === 'title' ? 'asc' : 'desc';

        if ($this->sortColumn() !== $column) {
            [$this->sort, $this->dir] = [$column, $first];
        } elseif ($this->sortDirection() === $first) {
            [$this->sort, $this->dir] = [$column, $first === 'asc' ? 'desc' : 'asc'];
        } elseif ($this->contentType->hierarchical()) {
            [$this->sort, $this->dir] = ['', ''];
        } else {
            [$this->sort, $this->dir] = [$column, $first];
        }

        $this->resetPage();
    }

    public function resetSort(): void
    {
        [$this->sort, $this->dir] = ['', ''];
        $this->resetPage();
    }

    public function clearFilters(): void
    {
        $this->search = '';
        $this->missing = '';
        $this->resetPage();
    }

    #[Computed]
    public function contentType(): ContentType
    {
        return app(ContentTypes::class)->get($this->type);
    }

    #[Computed]
    public function listQuery(): EntryListQuery
    {
        return EntryListQuery::for($this->contentType, $this->locale)
            ->tab($this->tab)
            ->search($this->search)
            ->missingIn($this->missing)
            ->sort($this->sortColumn(), $this->sortDirection());
    }

    /**
     * @return LengthAwarePaginator<int, Entry>
     */
    #[Computed]
    public function entries(): LengthAwarePaginator
    {
        return $this->listQuery->paginate($this->contentType->perPage());
    }

    /**
     * @return array{all: int, published: int, drafts: int, scheduled: int, trash: int}
     */
    #[Computed]
    public function counts(): array
    {
        return $this->listQuery->counts();
    }

    /**
     * Every language added to the site, the switched-off ones included.
     *
     * @return list<Language>
     */
    #[Computed]
    public function languages(): array
    {
        return app(ContentLocales::class)->all();
    }

    #[Computed]
    public function multilingual(): bool
    {
        return count($this->languages) > 1;
    }

    /**
     * The type's permissions for the actor, checked once per render instead of once per row.
     *
     * @return array{create: bool, edit: bool, delete: bool}
     */
    #[Computed]
    public function abilities(): array
    {
        $user = Auth::user();

        return [
            'create' => $user->can('create', [Entry::class, $this->type]),
            'edit' => $user->can($this->contentType->permission('edit')),
            'delete' => $user->can($this->contentType->permission('delete')),
        ];
    }

    /**
     * Published and draft (or scheduled) records of the row's group in the site's languages, from the loaded group.
     *
     * @return array{published: int, drafts: int, total: int}
     */
    public function translationStats(Entry $entry): array
    {
        $codes = array_map(fn (Language $language): string => $language->code, $this->languages);
        $present = $entry->groupEntries->filter(fn (Entry $record): bool => in_array($record->locale, $codes, true));
        $published = $present->filter(fn (Entry $record): bool => $record->state() === EntryState::Published)->count();

        return ['published' => $published, 'drafts' => $present->count() - $published, 'total' => count($codes)];
    }

    /**
     * The site's languages the row's group has no record in, for "Add translation".
     *
     * @return list<Language>
     */
    public function missingLanguages(Entry $entry): array
    {
        $present = $entry->groupEntries->pluck('locale')->push($entry->locale)->all();

        return array_values(array_filter($this->languages, fn (Language $language): bool => ! in_array($language->code, $present, true)));
    }

    public function isFiltered(): bool
    {
        return trim($this->search) !== '' || $this->missing !== '';
    }

    public function confirmTrash(int $entryId): void
    {
        $entry = $this->liveEntry($entryId);

        $this->authorize('delete', $entry);

        $this->trashingId = $entry->id;
        $this->trashingChildren = $entry->descendantsCount();
        $this->showTrashModal = true;
    }

    public function trash(TrashEntry $trashEntry): void
    {
        $entry = $this->liveEntry((int) $this->trashingId);

        $count = $trashEntry->handle(Auth::user(), $entry);

        Flux::toast(variant: 'success', text: $count > 1
            ? trans_choice('content.list.trash.done_with_children', $count)
            : __('content.list.trash.done'));

        $this->showTrashModal = false;
        $this->trashingId = null;
        $this->forgetList();
    }

    public function restore(int $entryId, RestoreEntry $restoreEntry): void
    {
        $entry = $this->trashedEntry($entryId);

        $this->authorize('restore', $entry);

        try {
            $restored = $restoreEntry->handle(Auth::user(), $entry);

            Flux::toast(variant: 'success', text: __('content.list.restored', ['title' => $restored->title]));
        } catch (ProtectedActionException $exception) {
            Flux::toast(variant: 'danger', text: $exception->getMessage());
        }

        $this->forgetList();
    }

    public function confirmDelete(int $entryId): void
    {
        $entry = $this->trashedEntry($entryId);

        $this->authorize('forceDelete', $entry);

        $this->deletingId = $entry->id;
        $this->deletingChildren = $entry->descendantsCount();
        $this->showDeleteModal = true;
    }

    public function deleteForGood(DeleteEntry $deleteEntry): void
    {
        $entry = $this->trashedEntry((int) $this->deletingId);

        try {
            $deleteEntry->handle(Auth::user(), $entry);

            Flux::toast(variant: 'success', text: __('content.list.delete.done'));
        } catch (ProtectedActionException $exception) {
            Flux::toast(variant: 'danger', text: $exception->getMessage());
        }

        $this->showDeleteModal = false;
        $this->deletingId = null;
        $this->forgetList();
    }

    #[Computed]
    public function trashingEntry(): ?Entry
    {
        return $this->trashingId ? Entry::query()->ofType($this->type)->find($this->trashingId, ['id', 'title']) : null;
    }

    #[Computed]
    public function deletingEntry(): ?Entry
    {
        return $this->deletingId ? Entry::onlyTrashed()->ofType($this->type)->find($this->deletingId, ['id', 'title']) : null;
    }

    public function sortColumn(): string
    {
        return $this->sort !== '' ? $this->sort : ($this->contentType->hierarchical() ? 'tree' : 'date');
    }

    public function sortDirection(): string
    {
        if (in_array($this->dir, ['asc', 'desc'], true)) {
            return $this->dir;
        }

        return in_array($this->sortColumn(), ['title', 'tree'], true) ? 'asc' : 'desc';
    }

    private function liveEntry(int $entryId): Entry
    {
        return Entry::query()->ofType($this->type)->findOrFail($entryId);
    }

    private function trashedEntry(int $entryId): Entry
    {
        return Entry::onlyTrashed()->ofType($this->type)->findOrFail($entryId);
    }

    private function validLocale(string $code): string
    {
        $locales = app(ContentLocales::class);

        return $code !== '' && $locales->has($code) ? $code : $locales->defaultCode();
    }

    private function validMissing(string $code): string
    {
        return $code !== '' && $code !== $this->locale && app(ContentLocales::class)->has($code) ? $code : '';
    }

    private function forgetList(): void
    {
        unset($this->entries, $this->counts, $this->listQuery, $this->trashingEntry, $this->deletingEntry);
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
    $activeCodes = array_map(fn ($language) => $language->code, app(App\Support\Localization\ContentLocales::class)->active());
    $timezone = config('admin.timezone');
    $trashDays = (int) config('content.trash_days');
@endphp

<section class="w-full space-y-6">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <flux:heading size="xl" level="1">{{ $contentType->pluralLabel() }}</flux:heading>
            <flux:subheading>{{ $contentType->description() }}</flux:subheading>
        </div>

        @if ($abilities['create'])
            <flux:button variant="primary" icon="plus" :href="route('admin.content.create', ['type' => $type, 'locale' => $locale])" wire:navigate data-test="create-entry">
                {{ $contentType->createLabel() }}
            </flux:button>
        @endif
    </div>

    <nav class="-mb-px flex gap-6 overflow-x-auto border-b border-zinc-200 text-sm dark:border-zinc-700" aria-label="{{ $contentType->pluralLabel() }}">
        @foreach (App\Support\Content\EntryListQuery::TABS as $tabKey)
            <button
                type="button"
                wire:key="tab-{{ $tabKey }}"
                wire:click="$set('tab', '{{ $tabKey }}')"
                @class([
                    '-mb-px flex shrink-0 items-center gap-1.5 border-b-2 pb-3 whitespace-nowrap transition-colors',
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
                    <flux:button variant="primary" size="sm" icon="plus" class="mt-4" :href="route('admin.content.create', ['type' => $type, 'locale' => $locale])" wire:navigate>
                        {{ $contentType->createLabel() }}
                    </flux:button>
                @endif
            </x-admin.empty-state>
        @else
            <x-admin.empty-state icon="funnel" :heading="__('content.list.empty_tab.title')" :description="__('content.list.empty_tab.description')" />
        @endif
    @else
        <div class="rounded-xl border border-zinc-200 bg-white px-4 shadow-xs dark:border-zinc-700 dark:bg-zinc-900/40">
            <flux:table :paginate="$this->entries" pagination:class="pb-3">
                <flux:table.columns>
                    @if ($inTrash)
                        <flux:table.column>{{ __('content.list.columns.title') }}</flux:table.column>
                    @else
                        <flux:table.column sortable :sorted="$this->sortColumn() === 'title'" :direction="$this->sortDirection()" wire:click="sort('title')">{{ __('content.list.columns.title') }}</flux:table.column>
                    @endif
                    @if ($showTranslations)
                        <flux:table.column class="max-md:hidden">{{ __('content.list.columns.translations') }}</flux:table.column>
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
                                            <a href="{{ $editUrl }}" wire:navigate class="truncate font-medium text-zinc-900 hover:underline dark:text-white" data-test="edit-entry-link-{{ $entry->id }}">{{ $entry->title }}</a>
                                            @if (! $inTrash && $state === App\Enums\EntryState::Draft)
                                                <flux:badge size="sm" color="zinc" class="shrink-0">{{ __('content.list.state.draft') }}</flux:badge>
                                            @elseif (! $inTrash && $state === App\Enums\EntryState::Scheduled)
                                                <flux:badge size="sm" color="sky" class="shrink-0">{{ __('content.list.state.scheduled') }}</flux:badge>
                                            @endif
                                        </div>
                                        <div class="truncate text-xs text-zinc-500 dark:text-zinc-400">{{ $entry->url(false) }}</div>
                                        @if ($inTrash)
                                            <div class="text-xs text-zinc-500 lg:hidden dark:text-zinc-400">{{ __('content.list.removed_on', ['date' => $entry->deleted_at->copy()->addDays($trashDays)->tz($timezone)->isoFormat('LL')]) }}</div>
                                        @endif
                                    </div>
                                </div>
                            </flux:table.cell>

                            @if ($showTranslations)
                                @php $stats = $this->translationStats($entry); @endphp
                                <flux:table.cell class="max-md:hidden">
                                    <flux:tooltip interactive position="bottom" align="start">
                                        <button type="button" class="flex items-center gap-2 rounded-md py-1 focus:outline-none focus-visible:ring-2 focus-visible:ring-accent" aria-label="{{ __('content.list.translations_summary', ['count' => $stats['published'] + $stats['drafts'], 'total' => $stats['total']]) }}" data-test="translations-{{ $entry->id }}">
                                            <span class="flex h-1.5 w-20 overflow-hidden rounded-full bg-zinc-100 dark:bg-zinc-700">
                                                <span class="bg-green-500" style="width: {{ round($stats['published'] / $stats['total'] * 100, 1) }}%"></span>
                                                <span class="bg-amber-400" style="width: {{ round($stats['drafts'] / $stats['total'] * 100, 1) }}%"></span>
                                            </span>
                                            <span class="text-xs text-zinc-500 tabular-nums dark:text-zinc-400">{{ $stats['published'] + $stats['drafts'] }}/{{ $stats['total'] }}</span>
                                        </button>

                                        <flux:tooltip.content class="w-72 border border-zinc-200 bg-white! p-3! text-zinc-700! shadow-lg dark:border-zinc-700 dark:bg-zinc-900! dark:text-zinc-300!">
                                            <div class="mb-2 flex items-center justify-between gap-3 text-xs">
                                                <span class="font-semibold text-zinc-900 dark:text-white">{{ __('content.list.columns.translations') }}</span>
                                                <span class="font-normal text-zinc-500 dark:text-zinc-400">{{ __('content.list.translations_summary', ['count' => $stats['published'] + $stats['drafts'], 'total' => $stats['total']]) }}</span>
                                            </div>
                                            @include('partials.admin.content.translations-grid', [
                                                'entry' => $entry,
                                                'languages' => $this->languages,
                                                'groupEntries' => $entry->groupEntries,
                                                'canCreate' => $abilities['create'],
                                            ])
                                        </flux:tooltip.content>
                                    </flux:tooltip>
                                </flux:table.cell>
                            @endif

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
                                        @if ($abilities['create'] && $this->multilingual && ($missingLanguages = $this->missingLanguages($entry)) !== [])
                                            <flux:menu.submenu icon="language" :heading="__('content.list.actions.translate')">
                                                @foreach ($missingLanguages as $language)
                                                    <flux:menu.item :href="route('admin.content.create', ['type' => $type, 'from' => $entry->id, 'locale' => $language->code])" wire:navigate wire:key="translate-{{ $entry->id }}-{{ $language->code }}">
                                                        <span class="me-2 font-mono text-xs text-zinc-400 uppercase">{{ $language->code }}</span>{{ $language->native }}
                                                        @unless (in_array($language->code, $activeCodes, true))
                                                            <span class="ms-2 text-xs text-zinc-400">{{ __('content.list.off') }}</span>
                                                        @endunless
                                                    </flux:menu.item>
                                                @endforeach
                                            </flux:menu.submenu>
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
</section>

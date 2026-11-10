<?php

use App\Enums\MediaKind;
use App\Models\Media;
use App\Models\MediaFolder;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('media.index.title')] class extends Component {
    use WithPagination;

    private const array SORTABLE = ['created_at', 'name', 'size'];

    private const array VIEWS = ['grid', 'list'];

    private const int GRID_STEP = 40;

    private const array FILTERS = ['folder', 'search', 'type', 'month', 'unused'];

    /**
     * null = all files, 'none' = files without a folder, otherwise a folder id.
     */
    #[Url]
    public ?string $folder = null;

    #[Url(except: '')]
    public string $search = '';

    #[Url(except: '')]
    public string $type = '';

    /**
     * '' or a month as Y-m in the admin timezone.
     */
    #[Url(except: '')]
    public string $month = '';

    #[Url(except: false)]
    public bool $unused = false;

    #[Url(except: 'grid')]
    public string $view = 'grid';

    #[Url(except: 'created_at')]
    public string $sortBy = 'created_at';

    #[Url(except: 'desc')]
    public string $sortDirection = 'desc';

    public int $gridLimit = self::GRID_STEP;

    /**
     * The file open in the details panel.
     */
    public ?int $selectedId = null;

    /**
     * Files picked for bulk actions.
     *
     * @var array<int, int>
     */
    public array $selected = [];

    public function mount(): void
    {
        $this->authorize('viewAny', Media::class);

        $this->forgetMissingFolder();
    }

    public function updated(string $property): void
    {
        if ($property === 'folder') {
            $this->forgetMissingFolder();
        }

        if ($property === 'view' && ! in_array($this->view, self::VIEWS, true)) {
            $this->view = 'grid';
        }

        if (in_array($property, [...self::FILTERS, 'view'], true)) {
            $this->resetPage();
            $this->gridLimit = self::GRID_STEP;
        }
    }

    public function sort(string $column): void
    {
        if (! in_array($column, self::SORTABLE, true)) {
            return;
        }

        if ($this->sortBy === $column) {
            $this->sortDirection = $this->sortDirection === 'asc' ? 'desc' : 'asc';
        } else {
            $this->sortBy = $column;
            $this->sortDirection = 'asc';
        }

        $this->resetPage();
    }

    public function showMore(): void
    {
        $this->gridLimit += self::GRID_STEP;
    }

    /**
     * The grid shows the first $gridLimit files; the list pages by 25.
     *
     * @return LengthAwarePaginator<int, Media>
     */
    #[Computed]
    public function media(): LengthAwarePaginator
    {
        $sortBy = in_array($this->sortBy, self::SORTABLE, true) ? $this->sortBy : 'created_at';
        $direction = $this->sortDirection === 'asc' ? 'asc' : 'desc';

        $query = $this->filteredQuery()
            ->withCount('usages')
            ->orderBy($sortBy, $direction)
            ->orderBy('id', $direction);

        return $this->view === 'list'
            ? $query->paginate(25)
            : $query->paginate($this->gridLimit, page: 1);
    }

    /**
     * Folders in tree order (each followed by its subfolders), with their depth.
     *
     * @return array<int, array{folder: MediaFolder, depth: int}>
     */
    #[Computed]
    public function folders(): array
    {
        $byParent = MediaFolder::tree()->groupBy(fn (MediaFolder $folder): int => $folder->parent_id ?? 0);
        $rows = [];

        $walk = function (int $parentId, int $depth) use (&$walk, &$rows, $byParent): void {
            foreach ($byParent->get($parentId, []) as $folder) {
                $rows[] = ['folder' => $folder, 'depth' => $depth];
                $walk($folder->id, $depth + 1);
            }
        };

        $walk(0, 0);

        return $rows;
    }

    /**
     * Months that have uploads, newest first, as Y-m => label in the admin timezone.
     *
     * @return array<string, string>
     */
    #[Computed]
    public function months(): array
    {
        $oldest = Media::query()->min('created_at');

        if ($oldest === null) {
            return [];
        }

        $timezone = (string) config('admin.timezone');
        $cursor = CarbonImmutable::parse($oldest, (string) config('app.timezone'))->tz($timezone)->startOfMonth();
        $last = CarbonImmutable::now($timezone)->startOfMonth();
        $months = [];

        while ($cursor <= $last) {
            $months[$cursor->format('Y-m')] = Str::ucfirst($cursor->isoFormat('MMMM YYYY'));
            $cursor = $cursor->addMonth();
        }

        return array_reverse($months, true);
    }

    #[Computed]
    public function currentFolder(): ?MediaFolder
    {
        if ($this->folder === null || $this->folder === 'none') {
            return null;
        }

        return collect($this->folders)->firstWhere('folder.id', (int) $this->folder)['folder'] ?? null;
    }

    /**
     * @return Collection<int, MediaFolder>
     */
    #[Computed]
    public function breadcrumb(): Collection
    {
        return $this->currentFolder?->breadcrumb() ?? collect();
    }

    /**
     * @return array{all: int, none: int}
     */
    #[Computed]
    public function totals(): array
    {
        return [
            'all' => Media::query()->count(),
            'none' => Media::query()->whereNull('folder_id')->count(),
        ];
    }

    /**
     * @return Builder<Media>
     */
    private function filteredQuery(): Builder
    {
        return Media::query()
            ->when($this->folder === 'none', fn (Builder $query) => $query->whereNull('folder_id'))
            ->when($this->currentFolder, fn (Builder $query, MediaFolder $folder) => $query->where('folder_id', $folder->id))
            ->when(MediaKind::tryFrom($this->type), fn (Builder $query, MediaKind $kind) => $query->where('kind', $kind))
            ->when($this->monthRange(), fn (Builder $query, array $range) => $query->whereBetween('created_at', $range))
            ->when($this->unused, fn (Builder $query) => $query->doesntHave('usages'))
            ->search($this->search);
    }

    /**
     * The selected month as a UTC range, so no database-specific date SQL is needed.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}|null
     */
    private function monthRange(): ?array
    {
        if (preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $this->month) !== 1) {
            return null;
        }

        $start = CarbonImmutable::createFromFormat('!Y-m', $this->month, (string) config('admin.timezone'));

        if (! $start instanceof CarbonImmutable) {
            return null;
        }

        return [$start->utc(), $start->endOfMonth()->utc()];
    }

    /**
     * A folder id that no longer exists (or isn't an id) falls back to "All files".
     */
    private function forgetMissingFolder(): void
    {
        if ($this->folder === null || $this->folder === 'none') {
            return;
        }

        if (! ctype_digit($this->folder) || ! MediaFolder::query()->whereKey((int) $this->folder)->exists()) {
            $this->folder = null;
        }
    }
}; ?>

<section
    class="w-full space-y-6"
    x-data
    x-init="
        try {
            const stored = localStorage.getItem('media.view');
            if (! new URLSearchParams(window.location.search).has('view') && ['grid', 'list'].includes(stored) && stored !== $wire.view) {
                $wire.set('view', stored);
            }
        } catch (error) {}
        $wire.$watch('view', (value) => { try { localStorage.setItem('media.view', value) } catch (error) {} });
    "
>
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <flux:heading size="xl" level="1">{{ __('media.index.title') }}</flux:heading>
            <flux:subheading>{{ __('media.index.description') }}</flux:subheading>
        </div>

        {{-- Task 11: the "Upload files" button (with media.upload) goes here. --}}
    </div>

    <div class="flex flex-col gap-3 lg:flex-row lg:items-center">
        <flux:input
            wire:model.live.debounce.300ms="search"
            icon="magnifying-glass"
            :placeholder="__('media.index.search')"
            :aria-label="__('media.index.search')"
            clearable
            class="lg:max-w-xs"
        />

        <div class="grid grid-cols-1 gap-3 sm:grid-cols-3 lg:flex">
            <flux:select wire:model.live="type" :aria-label="__('media.index.filters.type')" class="lg:w-44">
                <flux:select.option value="">{{ __('media.index.filters.all_types') }}</flux:select.option>
                @foreach (App\Enums\MediaKind::cases() as $kind)
                    <flux:select.option :value="$kind->value">{{ __('media.index.types.'.$kind->value) }}</flux:select.option>
                @endforeach
            </flux:select>

            <flux:select wire:model.live="month" :aria-label="__('media.index.filters.month')" class="lg:w-48">
                <flux:select.option value="">{{ __('media.index.filters.any_date') }}</flux:select.option>
                @foreach ($this->months as $value => $label)
                    <flux:select.option :value="$value">{{ $label }}</flux:select.option>
                @endforeach
            </flux:select>

            {{-- Not wire:model: a select would send the boolean back as a string. --}}
            <flux:select wire:change="$set('unused', $event.target.value === '1')" :aria-label="__('media.index.filters.usage')" class="lg:w-56">
                <flux:select.option value="0" :selected="! $unused">{{ __('media.index.filters.all_files') }}</flux:select.option>
                <flux:select.option value="1" :selected="$unused">{{ __('media.index.filters.unused') }}</flux:select.option>
            </flux:select>
        </div>

        <flux:spacer class="max-lg:hidden" />

        {{-- Task 11: the "Select several" button goes here. --}}

        <flux:button.group :aria-label="__('media.index.views.label')" class="self-start lg:self-auto">
            <flux:button
                icon="squares-2x2"
                :variant="$view === 'grid' ? 'filled' : 'outline'"
                wire:click="$set('view', 'grid')"
                :aria-label="__('media.index.views.grid')"
                :aria-pressed="$view === 'grid' ? 'true' : 'false'"
            />
            <flux:button
                icon="list-bullet"
                :variant="$view === 'list' ? 'filled' : 'outline'"
                wire:click="$set('view', 'list')"
                :aria-label="__('media.index.views.list')"
                :aria-pressed="$view === 'list' ? 'true' : 'false'"
            />
        </flux:button.group>
    </div>

    <div class="flex flex-col gap-6 md:flex-row md:items-start">
        @include('partials.admin.media.folder-tree', [
            'folder' => $folder,
            'folders' => $this->folders,
            'totals' => $this->totals,
        ])

        <div class="min-w-0 flex-1 space-y-4">
            <div class="flex min-h-8 items-center gap-2 text-sm">
                <flux:breadcrumbs :aria-label="__('media.index.folders.path')">
                    @if ($folder === null)
                        <flux:breadcrumbs.item>{{ __('media.index.folders.all') }}</flux:breadcrumbs.item>
                    @else
                        <flux:breadcrumbs.item href="#" wire:click.prevent="$set('folder', null)">{{ __('media.index.folders.all') }}</flux:breadcrumbs.item>
                        @if ($folder === 'none')
                            <flux:breadcrumbs.item>{{ __('media.index.folders.none') }}</flux:breadcrumbs.item>
                        @endif
                        @foreach ($this->breadcrumb as $crumb)
                            @if ($loop->last)
                                <flux:breadcrumbs.item wire:key="crumb-{{ $crumb->id }}">{{ $crumb->name }}</flux:breadcrumbs.item>
                            @else
                                <flux:breadcrumbs.item href="#" wire:click.prevent="$set('folder', '{{ $crumb->id }}')" wire:key="crumb-{{ $crumb->id }}">{{ $crumb->name }}</flux:breadcrumbs.item>
                            @endif
                        @endforeach
                    @endif
                </flux:breadcrumbs>
                <flux:text size="sm" variant="subtle">· {{ trans_choice('media.index.file_count', $this->media->total()) }}</flux:text>

                {{-- Task 10: the folder "⋮" menu (rename, delete) goes here. --}}
            </div>

            @if ($this->media->isEmpty())
                <x-admin.empty-state
                    icon="photo"
                    :heading="$this->totals['all'] > 0 ? __('media.index.no_results.title') : __('media.index.empty.title')"
                    :description="$this->totals['all'] > 0 ? __('media.index.no_results.description') : __('media.index.empty.description')"
                />
            @elseif ($view === 'list')
                @include('partials.admin.media.list', [
                    'media' => $this->media,
                    'sortBy' => $sortBy,
                    'sortDirection' => $sortDirection,
                ])
            @else
                @include('partials.admin.media.grid', [
                    'media' => $this->media,
                    'selectedId' => $selectedId,
                ])
            @endif
        </div>

        {{-- Task 10: the details panel for $selectedId goes here. --}}
    </div>

    {{-- Task 11: the bulk actions bar for $selected goes here. --}}
</section>

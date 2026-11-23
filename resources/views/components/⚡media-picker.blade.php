<?php

use Hata\Actions\Media\UpdateMedia;
use Hata\Models\Media;
use Hata\Models\MediaFolder;
use App\Models\User;
use Hata\Support\Media\MediaAcceptance;
use Hata\Support\Media\MediaFilters;
use Hata\Support\Media\MediaIds;
use Hata\Support\Media\StoreUploadedMedia;
use Hata\Support\Media\UploaderConfig;
use Flux\Flux;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Modelable;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;

/**
 * The media picker: placed next to a form field, it shows the chosen file(s) and opens the library in a modal.
 * Usage: <livewire:media-picker wire:model="form.field" accept="image" :multiple="false" :extensions="[...]" :label="__('…')" />
 * The value is a media id (or null), or with `multiple` a list of ids in the order they were picked.
 * The parent validates the value again on save (Hata\Rules\MediaSelection): the browser can send any id.
 *
 * Detached mode (<livewire:media-picker detached accept="image" />, one per page) renders only the modal, for the rich
 * text editor: the browser event `media-picker:open` with `{target, locale}` opens it, and a choice dispatches
 * `media-picker:picked` with `{target, id, url, alt, width, height}` instead of setting the value.
 */
new class extends Component {
    use WithFileUploads {
        _startUpload as startTemporaryUpload;
    }

    /**
     * Files the grid shows at first and adds on "Load more".
     */
    public const int STEP = 30;

    /**
     * The chosen id (null = none), or with `multiple` a list of ids. Livewire writes the parent's field here as it is,
     * so an empty string or a digit string is tolerated too; normalizeValue() turns them into null or an int.
     *
     * @var int|string|array<array-key, mixed>|null
     */
    #[Modelable]
    public int|string|array|null $value = null;

    /**
     * The ids the picker was mounted with (set on the first dehydrate, after Livewire wrote the parent's value):
     * without media.view the browser may only take files out of these.
     *
     * @var list<int>|null
     */
    #[Locked]
    public ?array $mountedIds = null;

    /**
     * The file a click just took out of a multiple selection and where it was, so the double click that follows
     * puts it back in place instead of at the end.
     *
     * @var array{id: int, index: int}|null
     */
    #[Locked]
    public ?array $lastUnpicked = null;

    /**
     * `image`, `document` or `any`.
     */
    #[Locked]
    public string $accept = 'any';

    #[Locked]
    public bool $multiple = false;

    /**
     * Extensions the file may have, narrower than `accept` (e.g. the logo's PNG, JPG, WebP and SVG); null means any.
     *
     * @var list<string>|null
     */
    #[Locked]
    public ?array $extensions = null;

    #[Locked]
    public ?string $label = null;

    /**
     * Only the modal, opened by a browser event and answering with one (see the class comment); always a single file.
     */
    #[Locked]
    public bool $detached = false;

    /**
     * Detached mode: who opened the modal (an editor's own name), handed back with the pick so only it reacts.
     */
    #[Locked]
    public string $target = '';

    /**
     * Detached mode: the language whose alt text the pick carries ('' = the primary alt language).
     */
    #[Locked]
    public string $altLocale = '';

    public bool $showModal = false;

    /**
     * 'library' or 'upload'.
     */
    public string $tab = 'library';

    public string $search = '';

    /**
     * '' or a month as Y-m in the admin timezone.
     */
    public string $month = '';

    /**
     * The open folder: '' = all files, 'none' = files without a folder, otherwise a folder id (set by the folder tiles
     * and the breadcrumb; an unknown id falls back to ''). Uploads go here too.
     */
    public string $folder = '';

    #[Locked]
    public int $limit = self::STEP;

    /**
     * Files picked in the open modal, in the order they were picked; "Select" puts them into the value.
     *
     * @var list<int>
     */
    #[Locked]
    public array $picked = [];

    /**
     * The file shown in the side panel.
     */
    #[Locked]
    public ?int $focusedId = null;

    /**
     * The focused file's alt text in the primary language.
     */
    public string $alt = '';

    /**
     * The file the upload queue just sent with $wire.upload(); storeUpload() turns it into a library file.
     */
    public ?TemporaryUploadedFile $upload = null;

    public ?int $uploadPickedSize = null;

    public ?string $uploadPickedExtension = null;

    public ?int $uploadFolderId = null;

    /**
     * @param  list<string>|null  $extensions
     */
    public function mount(string $accept = 'any', bool $multiple = false, ?array $extensions = null, ?string $label = null, bool $detached = false): void
    {
        $acceptance = new MediaAcceptance($accept, $extensions);

        $this->accept = $acceptance->accept;
        $this->multiple = $multiple && ! $detached;
        $this->extensions = $acceptance->extensions;
        $this->label = $label;
        $this->detached = $detached;
    }

    /**
     * Livewire writes a bound parent's value after mount(), so the value is brought into shape here, before every
     * snapshot: '' and other non-digit text become null (or [] with `multiple`), digit strings become ints.
     */
    public function dehydrate(): void
    {
        $this->value = $this->normalizeValue($this->value);
        $this->mountedIds ??= $this->valueIds();
    }

    /**
     * The value as the browser (or the parent's binding) sent it: only allowed, existing files are kept, and without
     * media.view only files the picker was mounted with (taking them out is all such a user can do).
     */
    public function updatedValue(): void
    {
        $ids = $this->acceptance()->filterIds($this->valueIds());

        if (! $this->actor()->can('viewAny', Media::class)) {
            $ids = array_values(array_intersect($ids, $this->mountedIds ?? []));
        }

        $this->value = $this->multiple ? $ids : ($ids[0] ?? null);
        unset($this->chosen);
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['search', 'month', 'folder'], true)) {
            $this->limit = self::STEP;
        }

        if ($property === 'folder' && ! in_array($this->folder, ['', 'none'], true)
            && (! ctype_digit($this->folder) || ! MediaFolder::query()->whereKey((int) $this->folder)->exists())) {
            $this->folder = '';
        }

        if ($property === 'tab' && ($this->tab !== 'upload' || ! $this->actor()->can('create', Media::class))) {
            $this->tab = 'library';
        }
    }

    public function openPicker(): void
    {
        $this->authorize('viewAny', Media::class);

        $this->picked = $this->acceptance()->filterIds($this->valueIds());
        $this->tab = 'library';
        $this->limit = self::STEP;
        $this->focus($this->picked === [] ? null : end($this->picked));
        $this->showModal = true;
    }

    /**
     * Detached mode: open the modal for an editor, with nothing picked. The target and the language come from the
     * browser, so anything but a short plain name (or a known interface language) is dropped.
     */
    public function openFor(string $target = '', string $locale = ''): void
    {
        $this->authorize('viewAny', Media::class);

        $this->target = preg_match('/^[A-Za-z0-9_.:-]{1,100}$/', $target) === 1 ? $target : '';
        $this->altLocale = array_key_exists($locale, (array) config('app.available_locales')) ? $locale : '';
        $this->picked = [];
        $this->lastUnpicked = null;
        $this->tab = 'library';
        $this->limit = self::STEP;
        $this->focus(null);
        $this->showModal = true;
    }

    /**
     * A click on a tile: picks the file (with `multiple`, adds it or takes it out) and shows it in the side panel.
     */
    public function pick(int $id): void
    {
        $this->authorize('viewAny', Media::class);

        if ($this->acceptance()->filterIds([$id]) === []) {
            return;
        }

        $index = array_search($id, $this->picked, true);
        $this->lastUnpicked = null;

        if (! $this->multiple) {
            $this->picked = [$id];
        } elseif ($index !== false) {
            $this->picked = array_values(array_diff($this->picked, [$id]));
            $this->lastUnpicked = ['id' => $id, 'index' => $index];
        } else {
            $this->picked = [...$this->picked, $id];
        }

        $this->focus($id);
    }

    /**
     * A double click: the file is picked (never taken out) and the selection is used at once.
     */
    public function pickAndChoose(int $id): void
    {
        $this->authorize('viewAny', Media::class);

        if ($this->acceptance()->filterIds([$id]) === []) {
            return;
        }

        if (! $this->multiple) {
            $this->picked = [$id];
        } elseif (! in_array($id, $this->picked, true)) {
            // The first click of the double click took an already picked file out: put it back where it was.
            $index = $this->lastUnpicked !== null && $this->lastUnpicked['id'] === $id ? $this->lastUnpicked['index'] : count($this->picked);
            $picked = $this->picked;
            array_splice($picked, $index, 0, [$id]);
            $this->picked = $picked;
        }

        $this->lastUnpicked = null;
        $this->choose();
    }

    /**
     * "Select": the picked files become the value and the modal closes.
     */
    public function choose(): void
    {
        $this->authorize('viewAny', Media::class);

        $ids = $this->acceptance()->filterIds($this->picked);

        if ($this->detached) {
            $this->handBack($ids[0] ?? null);

            return;
        }

        if ($this->multiple) {
            $this->value = $ids;
        } elseif ($ids !== []) {
            $this->value = $ids[0];
        }

        unset($this->chosen);
        $this->showModal = false;
    }

    /**
     * Take a file out of the value (all of it without an id). Needs no access to the library.
     */
    public function remove(?int $id = null): void
    {
        $this->value = $this->multiple && $id !== null
            ? array_values(array_diff($this->valueIds(), [$id]))
            : ($this->multiple ? [] : null);

        unset($this->chosen);
    }

    public function loadMore(): void
    {
        $this->authorize('viewAny', Media::class);

        $this->limit = min($this->limit + self::STEP, MediaIds::MAX);
    }

    /**
     * Save the focused file's alt text in the primary language; the other languages are kept.
     */
    public function saveAlt(UpdateMedia $updateMedia): void
    {
        $this->authorize('viewAny', Media::class);

        $media = $this->focused;

        if ($media === null) {
            return;
        }

        $this->resetErrorBag('alt');

        try {
            $updateMedia->handle($this->actor(), $media, [
                'alt' => [...($media->alt ?? []), Media::primaryAltLocale() => $this->alt],
            ]);
        } catch (ValidationException $exception) {
            $this->addError('alt', collect($exception->errors())->flatten()->first() ?? __('media.picker.invalid'));

            return;
        }

        unset($this->focused);
        Flux::toast(variant: 'success', text: __('media.picker.alt_saved'));
    }

    /**
     * Only the "upload" property takes temporary uploads, one file at a time, with media.view and media.upload.
     *
     * @param  array<int, mixed>  $fileInfo
     */
    public function _startUpload(string $name, array $fileInfo, bool $isMultiple): void
    {
        abort_unless($name === 'upload' && ! $isMultiple, 403);
        $this->authorize('viewAny', Media::class);
        $this->authorize('create', Media::class);

        $this->startTemporaryUpload($name, $fileInfo, $isMultiple);
    }

    /**
     * Turn the file the upload queue just sent into a library file and pick it (one file: in place of the picked one),
     * then show the library. A file of a kind or extension this field doesn't take is refused and not kept.
     *
     * @return array{ok: bool, id?: int, name?: string, meta?: string, error?: string}
     */
    public function storeUpload(StoreUploadedMedia $storeUploadedMedia): array
    {
        $this->authorize('viewAny', Media::class);
        $this->authorize('create', Media::class);

        $file = $this->upload;
        $pickedSize = $this->uploadPickedSize !== null && $this->uploadPickedSize > 0 ? $this->uploadPickedSize : null;
        $pickedExtension = $this->uploadPickedExtension;
        $folderId = $this->uploadFolderId;
        $this->reset('upload', 'uploadPickedSize', 'uploadPickedExtension', 'uploadFolderId');

        if (! $file instanceof TemporaryUploadedFile) {
            return ['ok' => false, 'error' => __('media.upload.failed')];
        }

        $result = $storeUploadedMedia->handle(
            $this->actor(), $file, $folderId, $pickedSize, $pickedExtension, $this->acceptance()->storedExtensions(),
        );

        if ($result['ok'] && isset($result['id'])) {
            $id = $result['id'];
            $this->picked = $this->multiple ? [...array_diff($this->picked, [$id]), $id] : [$id];
            $this->focus($id);
            $this->search = '';
            $this->month = '';
            $this->tab = 'library';
            unset($this->files, $this->loaded, $this->hasMore, $this->months);
        }

        return $result;
    }

    /**
     * The chosen files in the value's order, for the form side; missing ones are left out.
     *
     * @return Collection<int, Media>
     */
    #[Computed]
    public function chosen(): Collection
    {
        $ids = $this->valueIds();

        if ($ids === []) {
            return new Collection;
        }

        $media = Media::query()->whereKey($ids)->get()->keyBy('id');

        return new Collection(array_values(array_filter(array_map(fn (int $id): ?Media => $media->get($id), $ids))));
    }

    /**
     * The library files shown in the modal: the accepted kind, newest first, the first $limit of them.
     *
     * @return Collection<int, Media>
     */
    #[Computed]
    public function files(): Collection
    {
        return $this->loaded()->take($this->limit);
    }

    /**
     * Whether "Load more" has anything to load: files() asks for one row more than it shows, so no count is needed.
     */
    #[Computed]
    public function hasMore(): bool
    {
        return $this->loaded()->count() > $this->limit;
    }

    /**
     * @return Collection<int, Media>
     */
    #[Computed]
    public function loaded(): Collection
    {
        return $this->query()->latest()->orderByDesc('id')->limit($this->limit + 1)->get();
    }

    #[Computed]
    public function focused(): ?Media
    {
        if ($this->focusedId === null) {
            return null;
        }

        $media = Media::query()->find($this->focusedId);

        return $media !== null && $this->acceptance()->allows($media) ? $media : null;
    }

    /**
     * @return array<int, array{folder: MediaFolder, depth: int}>
     */
    #[Computed]
    public function folders(): array
    {
        return MediaFolder::treeRows();
    }

    #[Computed]
    public function currentFolder(): ?MediaFolder
    {
        if (! ctype_digit($this->folder)) {
            return null;
        }

        return collect($this->folders)->firstWhere('folder.id', (int) $this->folder)['folder'] ?? null;
    }

    /**
     * The open folder and its ancestors, top first.
     *
     * @return \Illuminate\Support\Collection<int, MediaFolder>
     */
    #[Computed]
    public function breadcrumb(): \Illuminate\Support\Collection
    {
        return $this->currentFolder?->breadcrumb(collect($this->folders)->pluck('folder')) ?? collect();
    }

    /**
     * Folder tiles above the files, as in the library: the open folder's direct subfolders, or the top-level folders
     * under "All files" (the picker has no folder tree). None for "No folder" and while a search or month is set.
     *
     * @return \Illuminate\Support\Collection<int, MediaFolder>
     */
    #[Computed]
    public function subfolders(): \Illuminate\Support\Collection
    {
        if ($this->folder === 'none' || $this->search !== '' || $this->month !== '') {
            return collect();
        }

        $current = $this->currentFolder;

        if ($current === null && $this->folder !== '') {
            return collect();
        }

        return MediaFolder::childrenOf($this->folders, $current?->id);
    }

    /**
     * Files without a folder, for the "No folder" tile under "All files" (null = no tile: in a folder or while filtering).
     */
    #[Computed]
    public function noFolderCount(): ?int
    {
        if ($this->folder !== '' || $this->search !== '' || $this->month !== '') {
            return null;
        }

        return Media::query()->whereNull('folder_id')->count();
    }

    /**
     * @return array<string, string>
     */
    #[Computed]
    public function months(): array
    {
        return MediaFilters::monthOptions();
    }

    /**
     * @return array<string, mixed>
     */
    #[Computed]
    public function uploaderConfig(): array
    {
        return [
            ...UploaderConfig::for($this->actor(), $this->acceptance()->uploadExtensions()),
            'single' => ! $this->multiple,
        ];
    }

    /**
     * Detached mode: tell the editor that opened the modal which image was chosen, then close. Nothing chosen keeps
     * the modal open.
     */
    private function handBack(?int $id): void
    {
        $media = $id === null ? null : Media::query()->find($id);

        if ($media === null) {
            return;
        }

        $this->dispatch(
            'media-picker:picked',
            target: $this->target,
            id: $media->id,
            url: $media->url(),
            alt: $media->altFor($this->altLocale !== '' ? $this->altLocale : Media::primaryAltLocale()),
            width: $media->width,
            height: $media->height,
        );

        $this->showModal = false;
    }

    protected function acceptance(): MediaAcceptance
    {
        return new MediaAcceptance($this->accept, $this->extensions);
    }

    /**
     * @return Builder<Media>
     */
    private function query(): Builder
    {
        $folder = $this->currentFolder;

        return (new MediaFilters(
            search: $this->search,
            type: $this->acceptance()->filterType(),
            month: $this->month,
            folder: $folder,
            noFolder: $this->folder === 'none',
        ))->apply(Media::query());
    }

    /**
     * Show a file (or none) in the side panel and load its alt text.
     */
    private function focus(?int $id): void
    {
        $this->focusedId = $id;
        unset($this->focused);
        $this->resetErrorBag('alt');
        $this->alt = $this->focused?->altFor(Media::primaryAltLocale(), fallback: false) ?? '';
    }

    /**
     * The value in its canonical shape: an int or null, or with `multiple` a list of ints.
     *
     * @return int|list<int>|null
     */
    private function normalizeValue(mixed $value): int|array|null
    {
        $ids = $this->idsOf($value);

        return $this->multiple ? $ids : ($ids[0] ?? null);
    }

    /**
     * The ids in the value, whatever shape it came in ('' and other non-digit text mean none).
     *
     * @return list<int>
     */
    private function valueIds(): array
    {
        return $this->idsOf($this->value);
    }

    /**
     * @return list<int>
     */
    private function idsOf(mixed $value): array
    {
        if (is_array($value)) {
            return $this->multiple ? MediaIds::normalize($value) : [];
        }

        return MediaIds::normalize([$value]);
    }

    private function actor(): User
    {
        $user = Auth::user();

        abort_unless($user instanceof User, 403);

        return $user;
    }
}; ?>

@php
    $canView = auth()->user()?->can('viewAny', Hata\Models\Media::class) ?? false;
    $chosen = $detached ? new Illuminate\Database\Eloquent\Collection : $this->chosen;
    $modalName = 'media-picker-'.$this->getId();
@endphp

<div
    @if ($detached)
        data-test="media-picker-detached"
        @if ($canView) x-on:media-picker:open.window="$wire.openFor(String($event.detail?.target ?? ''), String($event.detail?.locale ?? ''))" @endif
    @else
        class="space-y-2" role="group" @if ($label) aria-labelledby="media-picker-label-{{ $this->getId() }}" @endif data-test="media-picker"
    @endif
>
    @unless ($detached)
    @if ($label)
        <flux:label id="media-picker-label-{{ $this->getId() }}">{{ $label }}</flux:label>
    @endif

    @if ($chosen->isEmpty())
        <flux:text size="sm" variant="subtle">{{ __('media.picker.none') }}</flux:text>
    @else
        <ul class="space-y-2">
            @foreach ($chosen as $item)
                <li wire:key="picker-chosen-{{ $item->id }}" class="flex flex-wrap items-center gap-x-3 gap-y-2 rounded-lg border border-zinc-200 p-2 dark:border-white/10" data-test="media-picker-chosen">
                    <div class="size-14 shrink-0 overflow-hidden rounded-md bg-zinc-100 dark:bg-white/5">
                        @include('partials.admin.media.thumb', ['item' => $item, 'compact' => true])
                    </div>
                    {{-- The basis keeps the name readable: in a narrow column the buttons wrap under it instead of squeezing it. --}}
                    <div class="min-w-0 flex-1 basis-32">
                        <div class="truncate text-sm font-medium text-zinc-800 dark:text-white" title="{{ $item->name }}">{{ $item->name }}</div>
                        <div class="truncate text-xs text-zinc-500 dark:text-zinc-400">
                            {{ implode(' · ', array_filter([$item->humanSize(), $item->width && $item->height ? $item->width.' × '.$item->height : null])) }}
                        </div>
                    </div>
                    <div class="flex shrink-0 items-center gap-1">
                    @if (! $multiple && $canView)
                        <flux:button size="sm" wire:click="openPicker" data-test="media-picker-open">{{ __('media.picker.change') }}</flux:button>
                    @endif
                    <flux:button
                        size="sm"
                        variant="ghost"
                        :icon="$multiple ? 'x-mark' : null"
                        wire:click="remove({{ $multiple ? $item->id : '' }})"
                        :aria-label="__('media.picker.remove_file', ['name' => $item->name])"
                        data-test="media-picker-remove"
                    >{{ $multiple ? '' : __('media.picker.remove') }}</flux:button>
                    </div>
                </li>
            @endforeach
        </ul>
    @endif

    @if (! $canView)
        <flux:text size="sm" variant="subtle" data-test="media-picker-needs-access">{{ __('media.picker.needs_access') }}</flux:text>
    @elseif ($multiple || $chosen->isEmpty())
        <flux:button size="sm" icon="photo" wire:click="openPicker" data-test="media-picker-open">
            {{ $multiple && $chosen->isNotEmpty() ? __('media.picker.add') : __('media.picker.choose') }}
        </flux:button>
    @endif
    @endunless

    @if ($canView)
        <flux:modal :name="$modalName" wire:model.self="showModal" class="w-full max-w-5xl p-0!">
            @if ($showModal)
                @php
                    $canUpload = auth()->user()->can('create', Hata\Models\Media::class);
                    $canEdit = auth()->user()->can('media.edit');
                    $uploaderConfig = $this->uploaderConfig;
                    $acceptance = $this->acceptance();
                    $focused = $this->focused;
                    $locales = (array) config('app.available_locales');
                    $primaryLocale = Hata\Models\Media::primaryAltLocale();
                @endphp

                <div class="flex max-h-[85vh] flex-col" x-data="mediaUploader(@js($uploaderConfig))">
                    <div class="px-6 pt-5 pe-12">
                        <flux:heading size="lg">{{ $label ?? __('media.picker.title') }}</flux:heading>
                    </div>

                    <div role="tablist" aria-label="{{ __('media.picker.tabs.label') }}" class="flex gap-5 border-b border-zinc-200 px-6 pt-2 dark:border-white/10">
                        @foreach (['library' => __('media.picker.tabs.library'), 'upload' => __('media.picker.tabs.upload')] as $key => $text)
                            @continue($key === 'upload' && ! $canUpload)
                            <button
                                type="button"
                                role="tab"
                                aria-selected="{{ $tab === $key ? 'true' : 'false' }}"
                                wire:click="$set('tab', '{{ $key }}')"
                                @class([
                                    'h-10 border-b-2 text-sm font-medium transition-colors focus-visible:outline-2 focus-visible:outline-accent',
                                    'border-zinc-800 text-zinc-900 dark:border-white dark:text-white' => $tab === $key,
                                    'border-transparent text-zinc-500 hover:text-zinc-800 dark:text-zinc-400 dark:hover:text-white' => $tab !== $key,
                                ])
                                data-test="media-picker-tab-{{ $key }}"
                            >{{ $text }}</button>
                        @endforeach
                    </div>

                    @if ($tab === 'upload' && $canUpload)
                        <div class="min-h-0 flex-1 space-y-4 overflow-y-auto p-6">
                            <div
                                x-on:dragenter="dragEnter($event)"
                                x-on:dragover="dragOver($event)"
                                x-on:dragleave="dragLeave($event)"
                                x-on:drop="drop($event)"
                                x-bind:class="dragging && 'border-accent! bg-accent/5'"
                                class="flex flex-col items-center justify-center gap-3 rounded-xl border-2 border-dashed border-zinc-300 p-10 text-center dark:border-white/20"
                                data-test="media-picker-dropzone"
                            >
                                <flux:icon name="arrow-up-tray" variant="outline" class="size-8 text-zinc-500 dark:text-zinc-400" />
                                <div class="text-base font-semibold text-zinc-900 dark:text-white">{{ __('media.picker.upload_title') }}</div>
                                <flux:text size="sm" class="max-w-md">{{ __('media.picker.upload_hint') }}</flux:text>
                                <flux:button size="sm" x-on:click="$refs.pickerUploadInput.click()">{{ __('media.picker.upload_browse') }}</flux:button>
                                <input
                                    type="file"
                                    @if ($multiple) multiple @endif
                                    x-ref="pickerUploadInput"
                                    accept="{{ collect($uploaderConfig['accept'])->map(fn (string $extension): string => '.'.$extension)->implode(',') }}"
                                    class="hidden"
                                    x-on:change="pick($event.target)"
                                />
                            </div>

                            @include('partials.admin.media.upload-tiles')
                        </div>
                    @else
                        <div class="flex flex-wrap items-center gap-3 px-6 py-4">
                            {{-- The picker can sit inside a page's form: Enter here must not submit it. --}}
                            <flux:input
                                wire:model.live.debounce.300ms="search"
                                x-on:keydown.enter.prevent
                                icon="magnifying-glass"
                                :placeholder="__('media.picker.search')"
                                :aria-label="__('media.picker.search')"
                                clearable
                                class="sm:max-w-xs"
                            />
                            <flux:select wire:model.live="month" :aria-label="__('media.index.filters.month')" class="sm:w-48">
                                <flux:select.option value="">{{ __('media.index.filters.any_date') }}</flux:select.option>
                                @foreach ($this->months as $monthValue => $monthLabel)
                                    <flux:select.option :value="$monthValue">{{ $monthLabel }}</flux:select.option>
                                @endforeach
                            </flux:select>
                            @if ($acceptance->kind() !== null)
                                <flux:text size="sm">{{ __('media.picker.only.'.$acceptance->accept) }}</flux:text>
                            @endif
                        </div>

                        <div class="flex min-h-0 flex-1 flex-col border-t border-zinc-100 md:flex-row dark:border-white/5">
                            <div class="min-h-0 min-w-0 flex-1 space-y-4 overflow-y-auto px-6 py-4">
                                <div class="flex min-h-8 items-center text-sm" data-test="media-picker-breadcrumb">
                                    @include('partials.admin.media.breadcrumb', ['folder' => $folder, 'crumbs' => $this->breadcrumb, 'rootValue' => "''"])
                                </div>

                                @if ($canUpload)
                                    @include('partials.admin.media.upload-tiles')
                                @endif

                                @php $subfolders = $this->subfolders; @endphp
                                @if ($subfolders->isNotEmpty() || $this->noFolderCount !== null)
                                    @include('partials.admin.media.subfolders', ['subfolders' => $subfolders, 'canMove' => false, 'noFolderCount' => $this->noFolderCount])
                                @endif

                                @if ($this->files->isEmpty() && $subfolders->isNotEmpty())
                                    <flux:text size="sm" variant="subtle">{{ __('media.index.subfolders.no_files') }}</flux:text>
                                @elseif ($this->files->isEmpty())
                                    <flux:text class="py-10 text-center">{{ __('media.picker.empty') }}</flux:text>
                                @else
                                    <div class="grid grid-cols-3 gap-3 sm:grid-cols-4 lg:grid-cols-5" data-test="media-picker-grid">
                                        @foreach ($this->files as $item)
                                            @php
                                                $usable = $acceptance->allowsExtension($item);
                                                $isPicked = in_array($item->id, $picked, true);
                                            @endphp
                                            <button
                                                type="button"
                                                wire:key="picker-tile-{{ $item->id }}"
                                                @if ($usable)
                                                    {{-- A double click sends only pickAndChoose, not a second pick. --}}
                                                    x-on:click="if ($event.detail <= 1) $wire.pick({{ $item->id }})"
                                                    wire:dblclick="pickAndChoose({{ $item->id }})"
                                                    aria-pressed="{{ $isPicked ? 'true' : 'false' }}"
                                                    data-test="media-picker-tile-{{ $item->id }}"
                                                @else
                                                    disabled
                                                    title="{{ __('media.picker.not_allowed') }}"
                                                    data-test="media-picker-tile-disabled-{{ $item->id }}"
                                                @endif
                                                aria-label="{{ $item->name }}"
                                                @class([
                                                    'group min-w-0 rounded-xl p-1 text-start transition-colors',
                                                    'hover:bg-zinc-100 focus-visible:outline-2 focus-visible:outline-accent dark:hover:bg-white/5' => $usable,
                                                    'cursor-not-allowed opacity-50' => ! $usable,
                                                    'bg-zinc-200/70 outline-2 outline-accent dark:bg-white/10' => $isPicked,
                                                ])
                                            >
                                                <div class="relative aspect-square overflow-hidden rounded-lg bg-zinc-100 ring-1 ring-black/5 ring-inset dark:bg-white/5 dark:ring-white/10">
                                                    @include('partials.admin.media.thumb', ['item' => $item])

                                                    @if ($isPicked)
                                                        <div class="absolute end-1.5 top-1.5 flex size-6 items-center justify-center rounded-full bg-zinc-800 text-white ring-2 ring-white dark:bg-white dark:text-zinc-800 dark:ring-zinc-900">
                                                            @if ($multiple)
                                                                <span class="text-xs font-semibold">{{ array_search($item->id, $picked, true) + 1 }}</span>
                                                            @else
                                                                <flux:icon name="check" variant="micro" class="size-4" />
                                                            @endif
                                                        </div>
                                                    @endif
                                                </div>
                                                <div class="mt-1.5 truncate px-0.5 text-xs font-medium text-zinc-800 dark:text-white">{{ $item->name }}</div>
                                                @unless ($usable)
                                                    <div class="truncate px-0.5 text-xs text-zinc-500 dark:text-zinc-400">{{ __('media.picker.not_allowed') }}</div>
                                                @endunless
                                            </button>
                                        @endforeach
                                    </div>

                                    @if ($this->hasMore && $limit < Hata\Support\Media\MediaIds::MAX)
                                        <div class="flex justify-center">
                                            <flux:button size="sm" wire:click="loadMore" data-test="media-picker-load-more">{{ __('media.picker.load_more') }}</flux:button>
                                        </div>
                                    @endif
                                @endif
                            </div>

                            @if ($focused)
                                <aside aria-label="{{ __('media.picker.selected') }}" class="w-full shrink-0 space-y-4 overflow-y-auto border-t border-zinc-200 bg-zinc-50 p-4 md:w-80 md:border-t-0 md:border-s dark:border-white/10 dark:bg-white/5" data-test="media-picker-details">
                                    <div class="flex aspect-[3/2] items-center justify-center overflow-hidden rounded-lg bg-zinc-100 dark:bg-white/5">
                                        @if ($thumbUrl = $focused->thumbUrl())
                                            <img src="{{ $thumbUrl }}" alt="{{ $focused->altFor(app()->getLocale()) }}" class="size-full object-contain" />
                                        @else
                                            @include('partials.admin.media.thumb', ['item' => $focused])
                                        @endif
                                    </div>
                                    <div>
                                        <div class="text-sm font-semibold break-all text-zinc-800 dark:text-white">{{ $focused->name }}</div>
                                        <div class="mt-1 text-[13px] text-zinc-600 dark:text-zinc-300">
                                            {{ implode(' · ', array_filter([$focused->width && $focused->height ? $focused->width.' × '.$focused->height : null, $focused->humanSize()])) }}
                                        </div>
                                    </div>

                                    @if ($focused->kind === Hata\Enums\MediaKind::Image)
                                        <div class="space-y-2">
                                            <flux:label for="picker-alt-{{ $this->getId() }}">
                                                {{ __('media.details.alt') }}@if (count($locales) > 1) ({{ $locales[$primaryLocale] ?? $primaryLocale }})@endif
                                            </flux:label>
                                            @if ($canEdit)
                                                <flux:textarea id="picker-alt-{{ $this->getId() }}" wire:model="alt" rows="3" />
                                                <flux:error name="alt" />
                                                <flux:text size="sm">{{ __('media.picker.alt_note') }}</flux:text>
                                                <flux:button size="sm" wire:click="saveAlt" data-test="media-picker-save-alt">{{ __('media.picker.save_alt') }}</flux:button>
                                            @else
                                                <flux:text size="sm" id="picker-alt-{{ $this->getId() }}">{{ $alt !== '' ? $alt : __('media.picker.no_alt') }}</flux:text>
                                            @endif
                                        </div>
                                    @endif

                                    <flux:link :href="route('admin.media.index', ['folder' => $focused->folder_id ?? 'none'])" target="_blank" class="text-sm">
                                        {{ __('media.picker.open_in_library') }}
                                    </flux:link>
                                </aside>
                            @endif
                        </div>
                    @endif

                    <div class="flex flex-wrap items-center gap-3 border-t border-zinc-200 px-6 py-3.5 dark:border-white/10">
                        <flux:text size="sm">
                            {{ $multiple && $picked !== [] ? trans_choice('media.picker.selected_count', count($picked)) : __('media.picker.double_click') }}
                        </flux:text>
                        <flux:spacer />
                        <flux:modal.close>
                            <flux:button variant="outline">{{ __('common.actions.cancel') }}</flux:button>
                        </flux:modal.close>
                        <flux:button variant="primary" wire:click="choose" :disabled="$picked === [] && ! $multiple" data-test="media-picker-select">
                            {{ __('media.picker.select') }}
                        </flux:button>
                    </div>
                </div>
            @endif
        </flux:modal>
    @endif
</div>

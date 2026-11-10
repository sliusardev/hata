<?php

use App\Actions\Media\CreateFolder;
use App\Actions\Media\DeleteFolder;
use App\Actions\Media\DeleteMedia;
use App\Actions\Media\MoveMedia;
use App\Actions\Media\RenameFolder;
use App\Actions\Media\UpdateMedia;
use App\Actions\Media\UploadMedia;
use App\Actions\Media\ZipMedia;
use App\Enums\MediaKind;
use App\Exceptions\MediaUploadException;
use App\Livewire\Forms\MediaDetailsForm;
use App\Models\Media;
use App\Models\MediaFolder;
use App\Models\User;
use App\Support\Media\MediaIds;
use App\Support\Media\MediaTypes;
use App\Support\Media\ServerCapabilities;
use Carbon\CarbonImmutable;
use Flux\Flux;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;
use Livewire\WithPagination;

new #[Title('media.index.title')] class extends Component {
    use WithFileUploads {
        _startUpload as startTemporaryUpload;
    }
    use WithPagination;

    private const array SORTABLE = ['created_at', 'name', 'size'];

    private const array VIEWS = ['grid', 'list'];

    /**
     * Files the grid shows at first and adds on "Show more".
     */
    public const int GRID_STEP = 40;

    /**
     * The most files the grid shows at once; past this, the list view pages through them.
     */
    public const int GRID_MAX = 400;

    /**
     * Files per page of the list view.
     */
    public const int LIST_PER_PAGE = 25;

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

    #[Locked]
    public int $gridLimit = self::GRID_STEP;

    /**
     * The file open in the details panel. Changed only through select(), so the unsaved-changes guard can't be skipped.
     */
    #[Locked]
    public ?int $selectedId = null;

    /**
     * The file a Shift + click selects from: the one picked last.
     */
    #[Locked]
    public ?int $selectionAnchor = null;

    public MediaDetailsForm $details;

    /**
     * The details form as it was loaded, to detect unsaved changes.
     *
     * @var array<string, mixed>
     */
    #[Locked]
    public array $savedDetails = [];

    /**
     * The file to open once unsaved changes are discarded; 0 closes the panel.
     */
    #[Locked]
    public ?int $pendingSelectId = null;

    public bool $showDiscardModal = false;

    /**
     * Files the open delete confirmation is about (one file, or the bulk selection).
     *
     * @var array<int, int>
     */
    #[Locked]
    public array $deletingIds = [];

    public bool $showDeleteModal = false;

    public string $folderName = '';

    /**
     * The folder the name dialog renames; null when it creates a new one.
     */
    #[Locked]
    public ?int $renamingFolderId = null;

    public bool $showFolderModal = false;

    #[Locked]
    public ?int $deletingFolderId = null;

    /**
     * The folder name typed to confirm deleting a folder with many files.
     */
    public string $confirmName = '';

    public bool $showFolderDeleteModal = false;

    /**
     * Files picked for bulk actions. Ids come from the client, so every use re-checks them.
     *
     * @var array<int, int>
     */
    public array $selected = [];

    /**
     * Whether a click on a grid tile selects it for bulk actions instead of opening its details.
     */
    public bool $selecting = false;

    /**
     * The file the upload queue just sent with $wire.upload(); storeUpload() turns it into a library file.
     */
    public ?TemporaryUploadedFile $upload = null;

    /**
     * Size of the file the user picked, before the browser shrank it.
     */
    public ?int $uploadPickedSize = null;

    /**
     * Extension of the file the user picked, before the browser converted it (e.g. HEIC to JPEG).
     */
    public ?string $uploadPickedExtension = null;

    /**
     * The folder that was open when the file was queued (null = no folder), so switching folders mid-queue doesn't move it.
     */
    public ?int $uploadFolderId = null;

    public function mount(): void
    {
        $this->authorize('viewAny', Media::class);

        $this->forgetMissingFolder();

        if (! in_array($this->view, self::VIEWS, true)) {
            $this->view = 'grid';
        }
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

        if (in_array($property, self::FILTERS, true)) {
            $this->selected = [];
        }

        if ($property === 'selected' || str_starts_with($property, 'selected.')) {
            $this->selected = MediaIds::existing($this->selected);
        }
    }

    /**
     * Only the "upload" property takes temporary uploads, one file at a time, and only with media.upload.
     *
     * @param  array<int, mixed>  $fileInfo
     */
    public function _startUpload(string $name, array $fileInfo, bool $isMultiple): void
    {
        $this->authorize('create', Media::class);

        abort_unless($name === 'upload' && ! $isMultiple, 403);

        $this->startTemporaryUpload($name, $fileInfo, $isMultiple);
    }

    /**
     * Turn the file the upload queue just sent into a library file in the folder it was queued in.
     * A rejected file returns its error text instead of throwing, so the queue shows it on the tile and goes on.
     *
     * @return array{ok: bool, id?: int, name?: string, meta?: string, error?: string}
     */
    public function storeUpload(UploadMedia $uploadMedia): array
    {
        $this->authorize('create', Media::class);

        $file = $this->upload;
        $pickedSize = $this->uploadPickedSize !== null && $this->uploadPickedSize > 0 ? $this->uploadPickedSize : null;
        $pickedExtension = $this->uploadPickedExtension;
        $folderId = $this->uploadFolderId;
        $this->reset('upload', 'uploadPickedSize', 'uploadPickedExtension', 'uploadFolderId');

        if (! $file instanceof TemporaryUploadedFile) {
            return ['ok' => false, 'error' => __('media.upload.failed')];
        }

        $folder = $folderId === null ? null : MediaFolder::query()->find($folderId);

        if ($folderId !== null && $folder === null) {
            $file->delete();

            return ['ok' => false, 'error' => __('media.errors.folder_missing')];
        }

        try {
            $media = $uploadMedia->handle($this->actor(), $file, $folder, $pickedSize, $pickedExtension);
        } catch (MediaUploadException $exception) {
            return ['ok' => false, 'error' => $exception->getMessage()];
        } catch (AuthorizationException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            report($exception);

            return ['ok' => false, 'error' => __('media.upload.failed')];
        } finally {
            $file->delete();
        }

        unset($this->media, $this->totals, $this->folders, $this->months);

        return [
            'ok' => true,
            'id' => $media->id,
            'name' => $media->name,
            'meta' => $media->savedBytes() === null
                ? __('media.upload.done', ['size' => $media->humanSize()])
                : __('media.upload.done_compressed', [
                    'before' => Media::formatBytes((int) $media->original_size),
                    'after' => $media->humanSize(),
                ]),
        ];
    }

    /**
     * Ctrl/Cmd + click ("toggle") adds a file to the selection or takes it out; Shift + click ("range")
     * selects every file shown between the last clicked one and this one, in the order they're shown.
     */
    public function pick(int $id, string $mode): void
    {
        if ($mode !== 'range') {
            $this->toggleSelected($id);

            return;
        }

        $shown = $this->media->getCollection()->modelKeys();
        $from = $this->selectionAnchor === null ? false : array_search($this->selectionAnchor, $shown, true);
        $to = array_search($id, $shown, true);

        if ($from === false || $to === false) {
            $this->toggleSelected($id);

            return;
        }

        $range = array_slice($shown, min($from, $to), abs($to - $from) + 1);
        $this->selected = MediaIds::existing([...$this->selected, ...$range]);
    }

    /**
     * A list checkbox ticked or unticked by hand becomes the anchor for the next Shift + click.
     *
     * @param  mixed  $value  the whole new selection (a nested "selected.N" update is ignored)
     */
    public function updatingSelected(mixed $value, ?string $key = null): void
    {
        if ($key !== null || ! is_array($value)) {
            return;
        }

        $changed = array_merge(
            array_diff(MediaIds::normalize($value), $this->selected),
            array_diff($this->selected, MediaIds::normalize($value)),
        );

        if (count($changed) === 1) {
            $this->selectionAnchor = (int) reset($changed);
        }
    }

    /**
     * Add a file to the bulk selection or take it out ("Select several" in the grid).
     */
    public function toggleSelected(int $id): void
    {
        $this->selectionAnchor = $id;

        if (in_array($id, $this->selected, true)) {
            $this->selected = array_values(array_diff($this->selected, [$id]));

            return;
        }

        $this->selected = MediaIds::existing([...$this->selected, $id]);
    }

    /**
     * Select every file shown on this page, or unselect them when all of them already are.
     */
    public function togglePageSelection(): void
    {
        $pageIds = $this->media->getCollection()->modelKeys();

        $this->selected = array_diff($pageIds, $this->selected) === []
            ? array_values(array_diff($this->selected, $pageIds))
            : MediaIds::existing([...$this->selected, ...$pageIds]);
    }

    public function toggleSelecting(): void
    {
        $this->selecting = ! $this->selecting;
    }

    public function clearSelection(): void
    {
        $this->selected = [];
        $this->selecting = false;
    }

    /**
     * Ask before deleting the selected files; deleteConfirmed() then deletes them.
     */
    public function confirmBulkDelete(): void
    {
        $ids = MediaIds::existing($this->selected);
        $this->selected = $ids;

        if ($ids === []) {
            return;
        }

        if (! $this->authorizedOrToast('delete', Media::query()->findOrFail($ids[0]))) {
            return;
        }

        $this->deletingIds = $ids;
        unset($this->deletingMedia);
        $this->showDeleteModal = true;
    }

    /**
     * Download the selected files as one ZIP archive: the browser goes to the admin.media.zip route, which streams it.
     */
    public function downloadZip(): void
    {
        $this->authorize('viewAny', Media::class);

        $ids = MediaIds::existing($this->selected);

        if ($ids === [] || ! class_exists(ZipArchive::class)) {
            return;
        }

        if (count($ids) > ZipMedia::MAX_FILES) {
            Flux::toast(variant: 'danger', text: __('media.bulk.zip_too_large', ['files' => ZipMedia::MAX_FILES]));

            return;
        }

        $this->redirect(route('admin.media.zip', ['ids' => $ids]));
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
        $this->gridLimit = min($this->gridLimit + self::GRID_STEP, self::GRID_MAX);
    }

    /**
     * Open a file in the details panel, or ask first when the open one has unsaved changes.
     */
    public function select(int $id): void
    {
        $this->authorize('viewAny', Media::class);
        $this->selectionAnchor = $id;

        if ($id === $this->selectedId || ! Media::query()->whereKey($id)->exists()) {
            return;
        }

        if ($this->hasUnsavedDetails()) {
            $this->pendingSelectId = $id;
            $this->showDiscardModal = true;

            return;
        }

        $this->openDetails($id);
    }

    public function closeDetails(): void
    {
        if ($this->hasUnsavedDetails()) {
            $this->pendingSelectId = 0;
            $this->showDiscardModal = true;

            return;
        }

        $this->openDetails(null);
    }

    /**
     * Drop the unsaved changes and go where the user asked to.
     */
    public function discardChanges(): void
    {
        $target = $this->pendingSelectId ?? $this->selectedId;

        $this->showDiscardModal = false;
        $this->pendingSelectId = null;
        $this->openDetails($target === 0 ? null : $target);
    }

    public function saveDetails(UpdateMedia $updateMedia): void
    {
        $media = $this->selectedMedia;

        if ($media === null) {
            return;
        }

        $this->resetErrorBag();

        try {
            $updateMedia->handle($this->actor(), $media, $this->details->payload());
        } catch (ValidationException $exception) {
            foreach ($exception->errors() as $field => $messages) {
                $this->addError('details.'.$field, $messages[0]);
            }

            return;
        } catch (AuthorizationException) {
            $this->toastForbidden();

            return;
        }

        $this->openDetails($media->id);
        Flux::toast(variant: 'success', text: __('media.messages.saved'));
    }

    #[Computed]
    public function selectedMedia(): ?Media
    {
        if ($this->selectedId === null) {
            return null;
        }

        return Media::query()->with(['uploader', 'usages'])->find($this->selectedId);
    }

    public function confirmDelete(int $id): void
    {
        $media = Media::query()->find($id);

        if ($media === null) {
            return;
        }

        if (! $this->authorizedOrToast('delete', $media)) {
            return;
        }

        $this->deletingIds = [$media->id];
        unset($this->deletingMedia);
        $this->showDeleteModal = true;
    }

    /**
     * Delete the files the open confirmation is about.
     */
    public function deleteConfirmed(DeleteMedia $deleteMedia): void
    {
        $items = $this->deletingMedia;

        $this->showDeleteModal = false;
        $this->deletingIds = [];

        if ($items->isEmpty()) {
            return;
        }

        try {
            $count = $deleteMedia->handle($this->actor(), $items);
        } catch (AuthorizationException) {
            $this->toastForbidden();

            return;
        }

        $this->forgetDeletedMedia();
        Flux::toast(variant: 'success', text: trans_choice('media.messages.deleted', $count));
    }

    /**
     * @return EloquentCollection<int, Media>
     */
    #[Computed]
    public function deletingMedia(): EloquentCollection
    {
        if ($this->deletingIds === []) {
            return new EloquentCollection;
        }

        return Media::query()->with('usages')->whereKey($this->deletingIds)->get();
    }

    /**
     * Forget what a closed or cancelled delete dialog was about, so a later call can't act on it.
     */
    public function cancelPending(): void
    {
        $this->showDeleteModal = false;
        $this->deletingIds = [];
        $this->showFolderDeleteModal = false;
        $this->deletingFolderId = null;
        $this->confirmName = '';
        $this->resetErrorBag('confirmName');
    }

    /**
     * Move files into a folder (null = "No folder"); dropping tiles onto a folder calls this.
     *
     * @param  array<int, mixed>  $ids
     */
    public function moveTo(array $ids, ?int $folderId, MoveMedia $moveMedia): void
    {
        $ids = MediaIds::normalize($ids);

        $folder = $folderId === null ? null : MediaFolder::query()->find($folderId);
        $items = Media::query()->whereKey($ids)->get();

        if (($folderId !== null && $folder === null) || $items->isEmpty()) {
            return;
        }

        try {
            $moveMedia->handle($this->actor(), $items, $folder);
        } catch (AuthorizationException) {
            $this->toastForbidden();

            return;
        }

        $this->selected = array_values(array_diff($this->selected, $items->modelKeys()));

        if ($this->selectedId !== null && in_array($this->selectedId, $ids, true)) {
            $folderValue = $folder === null ? '' : (string) $folder->id;
            $this->details->folder_id = $folderValue;
            $this->savedDetails['folder_id'] = $folderValue;
        }

        unset($this->folders, $this->totals, $this->media);
        Flux::toast(variant: 'success', text: trans_choice('media.messages.moved', $items->count()));
    }

    /**
     * "Move to…" in the bulk bar: moves every selected file into the folder (null = no folder).
     */
    public function moveSelected(?int $folderId, MoveMedia $moveMedia): void
    {
        $this->moveTo($this->selected, $folderId, $moveMedia);
    }

    public function openNewFolder(): void
    {
        if (! $this->authorizedOrToast('manageFolders', Media::class)) {
            return;
        }

        $this->renamingFolderId = null;
        $this->folderName = '';
        $this->resetErrorBag('folderName');
        $this->showFolderModal = true;
    }

    public function openRenameFolder(int $id): void
    {
        if (! $this->authorizedOrToast('manageFolders', Media::class)) {
            return;
        }

        $folder = MediaFolder::query()->find($id);

        if ($folder === null) {
            return;
        }

        $this->renamingFolderId = $folder->id;
        $this->folderName = $folder->name;
        $this->resetErrorBag('folderName');
        $this->showFolderModal = true;
    }

    /**
     * Submit the folder name dialog.
     */
    public function saveFolder(): void
    {
        $this->renamingFolderId === null
            ? $this->createFolder($this->folderName)
            : $this->renameFolder($this->renamingFolderId, $this->folderName);
    }

    /**
     * Create a folder inside the open one ("All files" and "No folder" create at the top level).
     */
    public function createFolder(string $name): void
    {
        $this->runFolderAction(function () use ($name): void {
            app(CreateFolder::class)->handle($this->actor(), $name, $this->currentFolder);
        }, __('media.messages.folder_created'));
    }

    public function renameFolder(int $id, string $name): void
    {
        $folder = MediaFolder::query()->find($id);

        if ($folder === null) {
            return;
        }

        $this->runFolderAction(function () use ($folder, $name): void {
            app(RenameFolder::class)->handle($this->actor(), $folder, $name);
        }, __('media.messages.folder_renamed'));
    }

    public function confirmFolderDelete(int $id): void
    {
        $folder = MediaFolder::query()->find($id);

        if ($folder === null) {
            return;
        }

        if (! $this->authorizedOrToast('deleteFolders', Media::class)) {
            return;
        }

        $this->deletingFolderId = $folder->id;
        $this->confirmName = '';
        $this->resetErrorBag('confirmName');
        unset($this->deletingFolder, $this->folderDeletionSummary);
        $this->showFolderDeleteModal = true;
    }

    /**
     * Delete the folder of the open confirmation with its subfolders and files.
     * A folder with more than 10 files needs its exact name typed; that is checked here, not only in the browser.
     */
    public function deleteFolder(DeleteFolder $deleteFolder): void
    {
        $folder = $this->deletingFolder;
        $summary = $this->folderDeletionSummary;

        if ($folder === null || $summary === null) {
            $this->showFolderDeleteModal = false;

            return;
        }

        if ($summary['files'] > 10 && trim($this->confirmName) !== $folder->name) {
            $this->addError('confirmName', __('media.folders.delete.name_mismatch'));

            return;
        }

        $removedIds = [$folder->id, ...$folder->descendantIds()];

        try {
            $deleteFolder->handle($this->actor(), $folder);
        } catch (AuthorizationException) {
            $this->toastForbidden();

            return;
        }

        $this->showFolderDeleteModal = false;
        $this->deletingFolderId = null;
        $this->confirmName = '';

        if ($this->folder !== null && in_array((int) $this->folder, $removedIds, true)) {
            $this->folder = null;
            $this->resetPage();
        }

        unset($this->folders, $this->currentFolder, $this->breadcrumb);
        $this->forgetDeletedMedia();
        Flux::toast(variant: 'success', text: __('media.messages.folder_deleted'));
    }

    #[Computed]
    public function deletingFolder(): ?MediaFolder
    {
        return $this->deletingFolderId === null ? null : MediaFolder::query()->find($this->deletingFolderId);
    }

    /**
     * @return array{folders: int, files: int, used: int}|null
     */
    #[Computed]
    public function folderDeletionSummary(): ?array
    {
        return $this->deletingFolder?->deletionSummary();
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
            ? $query->paginate(self::LIST_PER_PAGE)
            : $query->paginate(min(max($this->gridLimit, self::GRID_STEP), self::GRID_MAX), page: 1);
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
     * Every month from the oldest upload to now, newest first, as Y-m => label in the admin timezone.
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
     * Config of the mediaUploader Alpine component (see resources/js/media-uploader.js).
     *
     * Browser resizing only runs while optimization is on; uploadCap is PHP's upload cap in bytes (null = none).
     *
     * @return array<string, mixed>
     */
    #[Computed]
    public function uploaderConfig(): array
    {
        return [
            'canUpload' => $this->actor()->can('create', Media::class),
            'clientResize' => config('media.optimize.enabled') && config('media.optimize.client_resize'),
            'clientSide' => (int) config('media.client_side'),
            'accept' => array_keys(MediaTypes::ALLOWED),
            'types' => array_map(fn (array $type): string => $type['kind']->value, MediaTypes::ALLOWED),
            'limits' => collect(MediaKind::cases())
                ->mapWithKeys(fn (MediaKind $kind): array => [$kind->value => MediaTypes::limitBytes($kind)])
                ->all(),
            'uploadCap' => app(ServerCapabilities::class)->uploadCapBytes(),
            'locale' => str_replace('_', '-', app()->getLocale()),
            'texts' => [
                'too_large' => __('media.errors.too_large'),
                'unsupported' => __('media.errors.unsupported'),
                'failed' => __('media.upload.failed'),
                'title' => __('media.upload.title'),
                'waiting' => __('media.upload.waiting'),
                'preparing' => __('media.upload.preparing'),
                'progress' => __('media.upload.progress'),
                'compressing' => __('media.upload.compressing'),
            ],
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

    private function actor(): User
    {
        $user = Auth::user();

        abort_unless($user instanceof User, 403);

        return $user;
    }

    private function toastForbidden(): void
    {
        Flux::toast(variant: 'danger', text: __('media.errors.forbidden'));
    }

    /**
     * Authorize the ability, showing the forbidden toast (instead of a 403 page) when it is denied.
     */
    private function authorizedOrToast(string $ability, mixed $arguments): bool
    {
        try {
            $this->authorize($ability, $arguments);
        } catch (AuthorizationException) {
            $this->toastForbidden();

            return false;
        }

        return true;
    }

    /**
     * Run a folder create/rename, showing name errors on the dialog's field.
     */
    private function runFolderAction(Closure $action, string $success): void
    {
        $this->resetErrorBag('folderName');

        try {
            $action();
        } catch (ValidationException $exception) {
            $this->addError('folderName', $exception->errors()['name'][0] ?? __('media.errors.folder_name'));

            return;
        } catch (AuthorizationException) {
            $this->toastForbidden();

            return;
        }

        $this->showFolderModal = false;
        $this->folderName = '';
        $this->renamingFolderId = null;
        unset($this->folders, $this->currentFolder, $this->breadcrumb);
        Flux::toast(variant: 'success', text: $success);
    }

    private function hasUnsavedDetails(): bool
    {
        return $this->selectedId !== null && $this->details->differsFrom($this->savedDetails);
    }

    /**
     * Load a file into the details panel (null closes it) and remember its saved state.
     */
    private function openDetails(?int $id): void
    {
        $this->selectedId = $id;
        unset($this->selectedMedia);
        $this->resetErrorBag();

        $media = $this->selectedMedia;

        if ($media === null) {
            $this->selectedId = null;
            $this->details->reset();
            $this->savedDetails = [];

            return;
        }

        $this->details->fillFromMedia($media);
        $this->savedDetails = $this->details->all();
    }

    /**
     * After files were deleted, close their details and drop them from the bulk selection.
     */
    private function forgetDeletedMedia(): void
    {
        if ($this->selectedId !== null && ! Media::query()->whereKey($this->selectedId)->exists()) {
            $this->openDetails(null);
        }

        $this->selected = $this->selected === []
            ? []
            : Media::query()->whereKey($this->selected)->pluck('id')->all();

        unset($this->media, $this->totals, $this->folders, $this->months);
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

@php
    $canManageFolders = auth()->user()->can('manageFolders', App\Models\Media::class);
    $canUpload = auth()->user()->can('create', App\Models\Media::class);
    $uploaderConfig = $this->uploaderConfig;
@endphp

<section
    class="w-full space-y-6"
    x-data="mediaUploader(@js($uploaderConfig))"
    x-on:dragenter.window="dragEnter($event)"
    x-on:dragover.window="dragOver($event)"
    x-on:dragleave.window="dragLeave($event)"
    x-on:drop.window="drop($event)"
    x-on:dragend.window="resetDrag()"
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

        @if ($canUpload)
            <flux:button variant="primary" icon="arrow-up-tray" x-on:click="$refs.uploadInput.click()" data-test="media-upload">
                {{ __('media.upload.button') }}
            </flux:button>
            <input
                type="file"
                multiple
                x-ref="uploadInput"
                accept="{{ collect($uploaderConfig['accept'])->map(fn (string $extension): string => '.'.$extension)->implode(',') }}"
                class="hidden"
                x-on:change="pick($event.target)"
            />
        @endif
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

        @if ($view === 'grid' && $this->media->isNotEmpty())
            <flux:button
                icon="check-circle"
                :variant="$selecting ? 'filled' : 'outline'"
                wire:click="toggleSelecting"
                :aria-pressed="$selecting ? 'true' : 'false'"
                class="self-start lg:self-auto"
                :tooltip="__('media.bulk.select_keys')"
                data-test="media-select-several"
            >
                {{ $selecting ? __('media.bulk.done_selecting') : __('media.bulk.select_several') }}
            </flux:button>
        @endif

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
            'canManageFolders' => $canManageFolders,
        ])

        <div class="flex min-w-0 flex-1 flex-col gap-6 xl:flex-row xl:items-start">
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

                @if ($this->currentFolder && $canManageFolders)
                    <x-admin.row-actions :label="__('media.folders.actions')" data-test="media-folder-actions">
                        <flux:menu.item icon="pencil-square" wire:click="openRenameFolder({{ $this->currentFolder->id }})">{{ __('media.folders.rename') }}</flux:menu.item>
                        @can('deleteFolders', App\Models\Media::class)
                            <flux:menu.item icon="trash" variant="danger" wire:click="confirmFolderDelete({{ $this->currentFolder->id }})">{{ __('common.actions.delete') }}</flux:menu.item>
                        @endcan
                    </x-admin.row-actions>
                @endif
            </div>

            @if ($selected !== [])
                @include('partials.admin.media.bulk-bar', ['count' => count($selected), 'canMove' => $canManageFolders, 'folders' => $this->folders])
            @endif

            @if ($canUpload)
                @include('partials.admin.media.upload-tiles')
            @endif

            @if ($this->media->isEmpty())
                <x-admin.empty-state
                    icon="photo"
                    :heading="$this->totals['all'] > 0 ? __('media.index.no_results.title') : __('media.index.empty.title')"
                    :description="$this->totals['all'] > 0 ? __('media.index.no_results.description') : __('media.index.empty.description')"
                />
            @elseif ($view === 'list')
                @include('partials.admin.media.list', [
                    'media' => $this->media,
                    'selected' => $selected,
                    'sortBy' => $sortBy,
                    'sortDirection' => $sortDirection,
                    'canMove' => $canManageFolders,
                ])
            @else
                @include('partials.admin.media.grid', [
                    'media' => $this->media,
                    'selectedId' => $selectedId,
                    'selected' => $selected,
                    'selecting' => $selecting,
                    'gridStep' => $this::GRID_STEP,
                    'canShowMore' => $gridLimit < $this::GRID_MAX,
                    'canMove' => $canManageFolders,
                ])
            @endif
        </div>

        @if ($this->selectedMedia)
            @include('partials.admin.media.details', [
                'media' => $this->selectedMedia,
                'folders' => $this->folders,
            ])
        @endif
        </div>
    </div>

    @include('partials.admin.media.dialogs')

    @if ($canUpload)
        @include('partials.admin.media.upload-queue', [
            'imageMb' => (int) config('media.limits.image_mb'),
            'documentMb' => (int) config('media.limits.document_mb'),
        ])
    @endif
</section>

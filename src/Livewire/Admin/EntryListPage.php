<?php

namespace Hata\Livewire\Admin;

use App\Models\User;
use Flux\Flux;
use Hata\Actions\Content\BulkEntries;
use Hata\Actions\Content\DeleteEntry;
use Hata\Actions\Content\EmptyEntryTrash;
use Hata\Actions\Content\PruneEntryTrash;
use Hata\Actions\Content\RestoreEntry;
use Hata\Actions\Content\SetEntryStatus;
use Hata\Actions\Content\TrashEntry;
use Hata\Enums\EntryStatus;
use Hata\Exceptions\ProtectedActionException;
use Hata\Models\Entry;
use Hata\Support\Content\ContentType;
use Hata\Support\Content\ContentTypes;
use Hata\Support\Content\EntryListQuery;
use Hata\Support\Localization\ContentLocales;
use Hata\Support\Localization\Language;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;
use Throwable;

/**
 * The list of one content type's records: tabs, search, the language filter, sorting,
 * the row actions, bulk selection and the trash. The page `hata::admin.content.index` extends it and holds the
 * markup (decision 32: a class, so PHPStan analyses it).
 *
 * @property-read ContentType $contentType
 * @property-read array<string, ContentType> $taxonomies
 * @property-read array<string, list<array{id: int, label: string}>> $termFilterOptions
 * @property-read EntryListQuery $listQuery
 * @property-read LengthAwarePaginator<int, Entry> $entries
 * @property-read array{all: int, published: int, drafts: int, scheduled: int, trash: int} $counts
 * @property-read list<Language> $languages
 * @property-read bool $multilingual
 * @property-read array{create: bool, edit: bool, delete: bool} $abilities
 * @property-read Entry|null $trashingEntry
 * @property-read Entry|null $deletingEntry
 */
abstract class EntryListPage extends Component
{
    use WithPagination;

    /**
     * The session key that remembers the language the list was last narrowed to (empty: every language).
     */
    public const string LOCALE_SESSION_KEY = 'content.list_locale';

    /**
     * The longest search term used; a longer one (a pasted text, a crafted query string) is cut.
     */
    public const int SEARCH_MAX = 200;

    /**
     * How many terms the filter of one taxonomy offers.
     */
    public const int TERM_FILTER_OPTIONS = 300;

    #[Locked]
    public string $type = '';

    #[Url(except: 'all')]
    public string $tab = 'all';

    #[Url(as: 'q', except: '')]
    public string $search = '';

    /**
     * Empty means every language of the site, which is the default.
     */
    #[Url(except: '')]
    public string $locale = '';

    /**
     * The term the list is narrowed to in each taxonomy of the type: taxonomy key => term id (`?term[categories]=5`).
     * A taxonomy without a choice has no key.
     *
     * @var array<string, string>
     */
    #[Url(as: 'term', except: [])]
    public array $termFilter = [];

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

    /**
     * The ids of the rows picked with the checkboxes (strings, as the checkboxes send them). Cleared whenever the
     * rows change; the bulk actions use only those that are records of this type.
     *
     * @var list<string>
     */
    public array $selected = [];

    /**
     * What the bulk dialog asks about: `trash` or `delete` for the picked rows, `empty` for the whole trash.
     */
    #[Locked]
    public string $bulkAction = '';

    #[Locked]
    public int $bulkCount = 0;

    public bool $showBulkModal = false;

    public function mount(string $type): void
    {
        abort_unless(app(ContentTypes::class)->isActive($type), 404);

        $this->authorize('viewAny', [Entry::class, $type]);

        $this->type = $type;
        $this->tab = in_array($this->tab, EntryListQuery::TABS, true) ? $this->tab : 'all';
        $this->search = mb_substr($this->search, 0, self::SEARCH_MAX);
        $this->locale = $this->validLocale($this->locale !== '' ? $this->locale : (string) session(self::LOCALE_SESSION_KEY));
        session()->put(self::LOCALE_SESSION_KEY, $this->locale);
        $this->termFilter = $this->validTerms($this->termFilter);

        // At most once an hour per type, after the response is sent: records whose time in the trash is over.
        if (Cache::add('content.prune_trash', true, now()->addHour())) {
            defer(function (): void {
                try {
                    app(PruneEntryTrash::class)->handle();
                } catch (Throwable $exception) {
                    report($exception);
                }
            });
        }
    }

    public function updatedTab(): void
    {
        $this->selected = [];
        $this->tab = in_array($this->tab, EntryListQuery::TABS, true) ? $this->tab : 'all';
        $this->resetPage();
    }

    public function updatedSearch(): void
    {
        $this->selected = [];
        $this->search = mb_substr($this->search, 0, self::SEARCH_MAX);
        $this->resetPage();
    }

    public function updatedLocale(): void
    {
        $this->termFilter = [];
        unset($this->termFilterOptions);
        $this->selected = [];
        $this->locale = $this->validLocale($this->locale);
        session()->put(self::LOCALE_SESSION_KEY, $this->locale);
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

        $this->selected = [];
        $this->resetPage();
    }

    public function updatedPaginators(): void
    {
        $this->selected = [];
    }

    public function resetSort(): void
    {
        [$this->sort, $this->dir] = ['', ''];
        $this->selected = [];
        $this->resetPage();
    }

    public function updatedTermFilter(): void
    {
        $this->termFilter = $this->validTerms($this->termFilter);
        $this->selected = [];
        $this->resetPage();
    }

    public function clearFilters(): void
    {
        $this->search = '';
        $this->termFilter = [];
        $this->selected = [];
        $this->resetPage();
    }

    /**
     * The taxonomies of the list's type that are on, by key (none for a taxonomy or a type without any).
     *
     * @return array<string, ContentType>
     */
    #[Computed]
    public function taxonomies(): array
    {
        $types = app(ContentTypes::class);

        return array_filter($types->taxonomiesOf($this->type), fn (ContentType $taxonomy): bool => $types->isActive($taxonomy->key()));
    }

    /**
     * The terms each taxonomy's filter offers, by taxonomy key: the live terms of the listed languages in tree order,
     * each with its language code while every language is listed. One query per taxonomy that has a filter, at most
     * `TERM_FILTER_OPTIONS` terms each.
     *
     * @return array<string, list<array{id: int, label: string}>>
     */
    #[Computed]
    public function termFilterOptions(): array
    {
        // Only the taxonomies that want a filter (ContentType::hasListFilter()).
        $options = array_fill_keys(array_keys(array_filter($this->taxonomies, fn (ContentType $taxonomy): bool => $taxonomy->hasListFilter())), []);

        if ($options === []) {
            return [];
        }

        // A query per taxonomy, each with its own limit, so a large one never crowds another out.
        foreach (array_keys($options) as $key) {
            $terms = Entry::query()
                ->ofType($key)
                ->whereIn('locale', $this->listedLocales())
                ->orderBy('locale')
                ->orderByRaw("replace(path, '/', ' ') asc")
                ->limit(self::TERM_FILTER_OPTIONS)
                ->get(['id', 'type', 'locale', 'title', 'path']);

            foreach ($terms as $term) {
                $options[$key][] = [
                    'id' => $term->id,
                    'label' => str_repeat("\u{00A0}\u{00A0}", min($term->depth(), 6)).$term->title.($this->locale === '' && $this->multilingual ? ' ('.mb_strtoupper($term->locale).')' : ''),
                ];
            }
        }

        return array_filter($options);
    }

    /**
     * The choices that are a live term of their own taxonomy (one query for all of them); anything else is dropped.
     *
     * @param  array<array-key, mixed>  $given
     * @return array<string, string>
     */
    protected function validTerms(array $given): array
    {
        $wanted = [];

        foreach ($this->taxonomies as $key => $taxonomy) {
            $id = $given[$key] ?? '';

            if ($taxonomy->hasListFilter() && is_string($id) && ctype_digit($id)) {
                $wanted[$key] = (int) $id;
            }
        }

        if ($wanted === []) {
            return [];
        }

        $types = Entry::query()->whereKey(array_values($wanted))->pluck('type', 'id');

        return array_map(strval(...), array_filter($wanted, fn (int $id, string $key): bool => ($types[$id] ?? null) === $key, ARRAY_FILTER_USE_BOTH));
    }

    #[Computed]
    public function contentType(): ContentType
    {
        return app(ContentTypes::class)->get($this->type);
    }

    #[Computed]
    public function listQuery(): EntryListQuery
    {
        return EntryListQuery::for($this->contentType, $this->locale, $this->listedLocales())
            ->tab($this->tab)
            ->search($this->search)
            ->terms(array_map(intval(...), $this->termFilter))
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

    public function isFiltered(): bool
    {
        return trim($this->search) !== '' || $this->termFilter !== [];
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

    /**
     * Opens the dialog for a bulk action that needs a confirmation.
     */
    public function confirmBulk(string $action): void
    {
        abort_unless(in_array($action, ['trash', 'delete', 'empty'], true), 404);
        abort_unless($this->abilities['delete'], 403);

        // Counted in the database: a pick may name rows that are gone or in another state by now.
        $this->bulkCount = match ($action) {
            'empty' => $this->counts['trash'],
            'trash' => Entry::query()->ofType($this->type)->whereKey($this->selectedIds())->count(),
            default => Entry::onlyTrashed()->ofType($this->type)->whereKey($this->selectedIds())->count(),
        };

        if ($this->bulkCount === 0) {
            return;
        }

        $this->bulkAction = $action;
        $this->showBulkModal = true;
    }

    public function runBulk(BulkEntries $bulk, EmptyEntryTrash $emptyTrash): void
    {
        abort_unless($this->abilities['delete'], 403);

        if ($this->bulkAction === '') {
            $this->showBulkModal = false;

            return;
        }

        $count = match ($this->bulkAction) {
            'trash' => $bulk->trash($this->actor(), $this->contentType, $this->selectedIds()),
            'delete' => $bulk->delete($this->actor(), $this->contentType, $this->selectedIds()),
            default => $emptyTrash->handle($this->actor(), $this->contentType, $this->listedLocales()),
        };

        Flux::toast(variant: 'success', text: $this->bulkAction === 'trash'
            ? trans_choice('content.list.trash.done_with_children', $count)
            : trans_choice('content.list.bulk.deleted', $count));

        $this->showBulkModal = false;
        $this->bulkAction = '';
        $this->selected = [];
        $this->forgetList();
    }

    /**
     * Publishes a row's record or takes it off the site, at once: it is undone the same way.
     */
    public function setStatus(int $entryId, string $status, SetEntryStatus $setStatus): void
    {
        $entry = $this->liveEntry($entryId);
        $status = EntryStatus::tryFrom($status) ?? abort(404);

        $setStatus->handle($this->actor(), $entry, $status);

        Flux::toast(variant: 'success', text: __($status === EntryStatus::Published ? 'content.list.status.published' : 'content.list.status.unpublished', ['title' => $entry->title]));

        $this->forgetList();
    }

    public function setStatusSelected(string $status, BulkEntries $bulk): void
    {
        abort_unless($this->abilities['edit'], 403);

        $status = EntryStatus::tryFrom($status) ?? abort(404);
        $count = $bulk->setStatus($this->actor(), $this->contentType, $this->selectedIds(), $status);

        if ($count > 0) {
            Flux::toast(variant: 'success', text: trans_choice($status === EntryStatus::Published ? 'content.list.bulk.published' : 'content.list.bulk.unpublished', $count));
        }

        $this->selected = [];
        $this->forgetList();
    }

    public function restoreSelected(BulkEntries $bulk): void
    {
        abort_unless($this->abilities['edit'], 403);

        $result = $bulk->restore($this->actor(), $this->contentType, $this->selectedIds());

        foreach ($result['failed'] as $message) {
            Flux::toast(variant: 'danger', text: $message);
        }

        if ($result['restored'] > 0) {
            Flux::toast(variant: 'success', text: trans_choice('content.list.bulk.restored', $result['restored']));
        }

        $this->selected = [];
        $this->forgetList();
    }

    protected function actor(): User
    {
        $user = Auth::user();

        abort_unless($user instanceof User, 403);

        return $user;
    }

    /**
     * @return list<int>
     */
    protected function selectedIds(): array
    {
        return array_values(array_unique(array_filter(array_map(intval(...), $this->selected), fn (int $id): bool => $id > 0)));
    }

    /**
     * The languages the list shows: the chosen one, or every language of the site.
     *
     * @return list<string>
     */
    protected function listedLocales(): array
    {
        return $this->locale !== '' ? [$this->locale] : app(ContentLocales::class)->codes();
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

    protected function liveEntry(int $entryId): Entry
    {
        return Entry::query()->ofType($this->type)->findOrFail($entryId);
    }

    protected function trashedEntry(int $entryId): Entry
    {
        return Entry::onlyTrashed()->ofType($this->type)->findOrFail($entryId);
    }

    protected function validLocale(string $code): string
    {
        $locales = app(ContentLocales::class);

        return $code !== '' && $locales->has($code) ? $code : '';
    }

    protected function forgetList(): void
    {
        unset($this->entries, $this->counts, $this->listQuery, $this->trashingEntry, $this->deletingEntry);

        // The action emptied the page the list was on: show the last one that still has rows.
        if ($this->getPage() > 1 && $this->entries->isEmpty()) {
            $this->setPage(max(1, $this->entries->lastPage()));
            unset($this->entries);
        }
    }
}

<?php

namespace Hata\Livewire\Admin\Concerns;

use Closure;
use Flux\Flux;
use Hata\Contracts\AdminUser;
use Hata\Exceptions\ProtectedActionException;
use Hata\Support\Admin\Lists\BulkAction;
use Hata\Support\Admin\Lists\BulkActions;
use Hata\Support\Admin\Lists\ListContext;
use Hata\Support\Admin\Lists\RowAction;
use Hata\Support\Admin\Lists\RowActions;
use Hata\Support\Admin\Lists\TableColumn;
use Hata\Support\Admin\Lists\TableColumns;
use Hata\Support\Admin\Lists\TableFilter;
use Hata\Support\Admin\Lists\TableFilters;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;

/**
 * What an admin list page takes from the registries: the columns the signed-in user sees, their eager loads and
 * their sorts, and the filters. The page names its target in `listContext()`.
 *
 * @property-read list<TableColumn> $listColumns
 * @property-read list<TableFilter> $listFilters
 * @property-read array<string, array<array-key, string>> $listFilterOptions
 * @property-read list<RowAction> $listRowActions
 * @property-read RowAction|null $pendingRowAction
 * @property-read list<BulkAction> $listBulkActions
 * @property-read BulkAction|null $pendingBulkAction
 */
trait HasListExtensions
{
    /**
     * The chosen value of each registered filter, by key (`?filter[key]=value`).
     *
     * @var array<string, mixed>
     */
    #[Url(as: 'filter', except: [])]
    public array $filters = [];

    /**
     * The action a dialog waits for a confirmation of, and the record it was opened for.
     */
    #[Locked]
    public ?string $rowActionKey = null;

    #[Locked]
    public ?int $rowActionId = null;

    public bool $showRowActionModal = false;

    /**
     * The ids of the rows picked with the checkboxes (strings, as the checkboxes send them). Cleared whenever the
     * rows change; a bulk action gets only those that are records of the list's view.
     *
     * @var list<string>
     */
    public array $selected = [];

    /**
     * The bulk action a dialog waits for a confirmation of.
     */
    #[Locked]
    public ?string $bulkActionKey = null;

    public bool $showBulkActionModal = false;

    abstract protected function listContext(): ListContext;

    /**
     * The list's own query for one record: everything that scopes the list for the current view (the type, the
     * language filter, the tab; the users list, all users), and nothing that only narrows it (search, filters). It is
     * the security boundary of `runRowAction()`: an id outside it is a 404.
     *
     * @return Builder<*>
     */
    abstract protected function recordQuery(): Builder;

    /**
     * Drops what the page cached, after an action changed records.
     */
    abstract protected function forgetList(): void;

    /**
     * Checks again what the page's `mount()` checks (the list is there and the user may open it): run before a
     * registered action, so a user who lost the list's permission while the page was open cannot act from it.
     */
    abstract protected function authorizeList(): void;

    /**
     * @return list<TableColumn>
     */
    #[Computed]
    public function listColumns(): array
    {
        return app(TableColumns::class)->for(Auth::user(), $this->listContext());
    }

    /**
     * Adds every visible column's eager loads to the page's query, once.
     *
     * @param  Builder<*>  $query
     * @return Builder<*>
     */
    protected function applyColumnLoads(Builder $query): Builder
    {
        foreach ($this->listColumns as $column) {
            if ($column->with instanceof Closure) {
                ($column->with)($query);
            } elseif ($column->with !== []) {
                $query->with($column->with);
            }
        }

        return $query;
    }

    /**
     * The sort of a visible, sortable column, or null.
     */
    protected function registeredSort(string $key): ?Closure
    {
        foreach ($this->listColumns as $column) {
            if ($column->key === $key) {
                return $column->sort;
            }
        }

        return null;
    }

    /**
     * @return list<TableFilter>
     */
    #[Computed]
    public function listFilters(): array
    {
        return app(TableFilters::class)->for(Auth::user(), $this->listContext());
    }

    /**
     * The options of every visible filter, by filter key; each filter's closure runs once per render.
     *
     * @return array<string, array<array-key, string>>
     */
    #[Computed]
    public function listFilterOptions(): array
    {
        $context = $this->listContext();
        $options = [];

        foreach ($this->listFilters as $filter) {
            $options[$filter->key] = ($filter->options)($context);
        }

        return $options;
    }

    /**
     * The value of every visible filter that holds one of its options, by filter key. The one rule for what narrows
     * the list: `applyFilters()` applies these, and only these make the list filtered (`hasListFilterValue()`), so a
     * forged, unknown or hidden key, or a value that is not an option, counts for nothing.
     *
     * @return array<string, string>
     */
    protected function activeListFilters(): array
    {
        $active = [];

        foreach ($this->listFilters as $filter) {
            $value = $this->filters[$filter->key] ?? '';

            if (is_string($value) && $value !== '' && array_key_exists($value, $this->listFilterOptions[$filter->key])) {
                $active[$filter->key] = $value;
            }
        }

        return $active;
    }

    /**
     * Narrows the page's query by every visible filter whose value is one of its options.
     *
     * @param  Builder<*>  $query
     * @return Builder<*>
     */
    protected function applyFilters(Builder $query): Builder
    {
        $filters = [];

        foreach ($this->listFilters as $filter) {
            $filters[$filter->key] = $filter;
        }

        foreach ($this->activeListFilters() as $key => $value) {
            ($filters[$key]->apply)($query, $value);
        }

        return $query;
    }

    /**
     * Whether a registered filter narrows the list (see `activeListFilters()`).
     */
    protected function hasListFilterValue(): bool
    {
        return $this->activeListFilters() !== [];
    }

    public function updatedFilters(): void
    {
        $this->selected = [];
        $this->resetPage();
    }

    /**
     * The registered row actions this list shows in its tab, for the signed-in user (a record's own check is
     * `RowAction::allows()`).
     *
     * @return list<RowAction>
     */
    #[Computed]
    public function listRowActions(): array
    {
        return app(RowActions::class)->for(Auth::user(), $this->listContext());
    }

    /**
     * The action the confirmation dialog is open for.
     */
    #[Computed]
    public function pendingRowAction(): ?RowAction
    {
        foreach ($this->listRowActions as $action) {
            if ($action->key === $this->rowActionKey && ! $action->isLink()) {
                return $action;
            }
        }

        return null;
    }

    /**
     * Opens the shared dialog when the action asks for a confirmation, else runs it. The record is checked first
     * either way, so the dialog never opens for a record outside the view or one the user may not act on.
     */
    public function confirmRowAction(string $key, int $id): void
    {
        $this->authorizeList();
        $action = $this->rowActionNamed($key);

        if ($action->confirm === null) {
            $this->runRowAction($key, $id);

            return;
        }

        $this->recordFor($action, $id);

        $this->rowActionKey = $key;
        $this->rowActionId = $id;
        $this->showRowActionModal = true;
    }

    /**
     * Runs what the dialog was opened for.
     */
    public function runPendingRowAction(): void
    {
        abort_if($this->rowActionKey === null || $this->rowActionId === null, 404);

        $this->runRowAction($this->rowActionKey, $this->rowActionId);
    }

    /**
     * Runs a handler action on one record: the action must be visible in this list for this user, the record must be
     * in `recordQuery()` (404), and the action must allow it (403), all before the handler is resolved.
     */
    public function runRowAction(string $key, int $id): void
    {
        $this->authorizeList();
        $action = $this->rowActionNamed($key);
        $user = $this->rowActionUser();
        $record = $this->recordFor($action, $id);

        $this->runHandler(fn (): mixed => app($action->handler)($record, $user));

        $this->showRowActionModal = false;
        $this->rowActionKey = null;
        $this->rowActionId = null;
        unset($this->pendingRowAction);
        $this->forgetList();
    }

    /**
     * Forgets the pending action when its dialog closes (Cancel included), so a stale key never outlives it.
     */
    public function updatedShowRowActionModal(bool $open): void
    {
        if (! $open) {
            $this->rowActionKey = null;
            $this->rowActionId = null;
            unset($this->pendingRowAction);
        }
    }

    /**
     * The registered bulk actions this list shows in its tab, for the signed-in user. When there are none, the list
     * draws no checkboxes and no bar of its own.
     *
     * @return list<BulkAction>
     */
    #[Computed]
    public function listBulkActions(): array
    {
        return app(BulkActions::class)->for(Auth::user(), $this->listContext());
    }

    /**
     * The bulk action the confirmation dialog is open for.
     */
    #[Computed]
    public function pendingBulkAction(): ?BulkAction
    {
        foreach ($this->listBulkActions as $action) {
            if ($action->key === $this->bulkActionKey) {
                return $action;
            }
        }

        return null;
    }

    /**
     * Opens the shared dialog when the action asks first (a confirmation text or `danger`), else runs it.
     */
    public function confirmBulkAction(string $key): void
    {
        $this->authorizeList();
        $action = $this->bulkActionNamed($key);

        if (! $action->asksFirst()) {
            $this->runBulkAction($key);

            return;
        }

        $this->bulkActionKey = $key;
        $this->showBulkActionModal = true;
    }

    /**
     * Runs what the dialog was opened for.
     */
    public function runPendingBulkAction(): void
    {
        abort_if($this->bulkActionKey === null, 404);

        $this->runBulkAction($this->bulkActionKey);
    }

    /**
     * Runs a bulk action once, on the selected records: the action must be visible in this list for this user (404),
     * and the records are loaded through `recordQuery()`, so an id outside the view is dropped, never handed over.
     */
    public function runBulkAction(string $key): void
    {
        $this->authorizeList();
        $action = $this->bulkActionNamed($key);
        $user = $this->rowActionUser();
        $ids = $this->selectedIds();

        $records = $ids === [] ? $this->recordQuery()->getModel()->newCollection() : $this->recordQuery()->whereKey($ids)->get();

        $this->closeBulkAction();
        $this->selected = [];

        if ($records->isEmpty()) {
            return;
        }

        $this->runHandler(fn (): mixed => app($action->handler)($records, $user));

        $this->forgetList();
    }

    /**
     * Forgets the pending bulk action when its dialog closes (Cancel included).
     */
    public function updatedShowBulkActionModal(bool $open): void
    {
        if (! $open) {
            $this->closeBulkAction();
        }
    }

    /**
     * @return list<int>
     */
    protected function selectedIds(): array
    {
        return array_values(array_unique(array_filter(array_map(intval(...), $this->selected), fn (int $id): bool => $id > 0)));
    }

    /**
     * Calls a row or bulk handler: the text it returns is a success toast; a refusal (`ProtectedActionException`)
     * or a `ValidationException` it throws is a danger toast with its message, as Hata's own list actions show one.
     *
     * @param  Closure(): mixed  $handler
     */
    private function runHandler(Closure $handler): void
    {
        try {
            $message = $handler();
        } catch (ProtectedActionException $exception) {
            Flux::toast(variant: 'danger', text: $exception->getMessage());

            return;
        } catch (ValidationException $exception) {
            $first = collect($exception->errors())->flatten()->first();

            Flux::toast(variant: 'danger', text: is_string($first) ? $first : $exception->getMessage());

            return;
        }

        if (is_string($message) && $message !== '') {
            Flux::toast(variant: 'success', text: $message);
        }
    }

    private function closeBulkAction(): void
    {
        $this->showBulkActionModal = false;
        $this->bulkActionKey = null;
        unset($this->pendingBulkAction);
    }

    private function bulkActionNamed(string $key): BulkAction
    {
        foreach ($this->listBulkActions as $action) {
            if ($action->key === $key) {
                return $action;
            }
        }

        abort(404);
    }

    private function rowActionNamed(string $key): RowAction
    {
        foreach ($this->listRowActions as $action) {
            if ($action->key === $key && ! $action->isLink()) {
                return $action;
            }
        }

        abort(404);
    }

    private function recordFor(RowAction $action, int $id): Model
    {
        $record = $this->recordQuery()->whereKey($id)->firstOrFail();

        abort_unless($action->allows($this->rowActionUser(), $record, $this->listContext()), 403);

        return $record;
    }

    private function rowActionUser(): AdminUser
    {
        $user = Auth::user();

        abort_unless($user instanceof AdminUser, 403);

        return $user;
    }
}

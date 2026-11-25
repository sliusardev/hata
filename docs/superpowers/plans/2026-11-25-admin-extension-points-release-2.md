# Admin extension points, release 2 (v0.5.0) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** A site or module adds list columns, list filters, row and bulk actions to the content and users lists, cards to
the entry form, the user form, the profile page and registration, and switches parts of a content type's form off —
all through `Hata\Admin`, never by editing a Hata file.

**Architecture:** Per-list and per-form points are registries keyed by a *target* (`users`, `content.<type>`,
`content.*`, `content.@records`, `content.@taxonomies`, `profile`, `register`), built on release 1's `Registry` base:
a `TargetedRegistry` records add/remove/modify per target and resolves a concrete target by replaying them wide to
narrow into a fresh `Registry`. Lists share one trait (`HasListExtensions`) for columns, filters and actions; forms
share one service (`FormExtensions`) and a Livewire trait (`HasFormExtensions`) so a card's fields validate with the
form and save in its transaction.

**Tech Stack:** PHP 8.4, Laravel 13, Livewire 4.4, Flux 2.21 free, Fortify, Pest 5 on Testbench, PHPStan level 7.

**Spec:** `docs/superpowers/specs/2026-11-25-admin-extension-points-design.md`, sections 1 (targets, list context),
6–10 and "The same extension on the user's own profile and on registration". Release 1 is shipped (v0.4.1): read
`docs/specs/admin-extensions.md` for the base as built.

## Global Constraints

- Flux **free** only; no new Composer or npm dependency.
- Every new label in `resources/lang/en` **and** `uk` under `hata::`; `TranslationParityTest` passes.
- Keys follow the base's rules; entries are `final readonly` classes implementing `Entry`, `use HasChanges`.
- `remove()` hides a key for good; replacing = `add()` with the same key; `modify()`/`remove()` of a missing key is skipped and shown by `hata:admin`.
- A list column or filter costs at most **one query per page**, never per row; Hata's own lists are asserted with the new query helper.
- No sorting by keys of the JSON `fields` column out of the box (decision 57).
- A site's row and bulk actions never show in Trash unless their `visibleIn` says so; a handler in Trash loads records with `onlyTrashed()` through the list's query.
- Form cards validate together with the form (any error stops the whole save) and save **inside the form's transaction, after the record is saved and before any redirect**; an exception in a card's `save()` rolls the record back.
- Hata's own form blocks stay as they are; Hata's own row actions and bulk actions stay as they are (a site adds, never replaces them).
- The first column of each list (name/title with its link, tree indent, language chips, badges) and the "⋮" column are fixed.
- Base Livewire classes and traits live in `src/Livewire/Admin` (decision 32); shipped file names ASCII.
- Rebuild and commit `dist/` after any change to `resources/css`/`resources/js` or new Tailwind classes in views.
- The suite passes on SQLite, MySQL and PostgreSQL (decision 82); run one database at a time; no `pest --parallel`.
- UI changes (filters toolbar, selection on the users list, cards in forms) go through the user's browser pass before the release; everything shown to clients must look polished.

## Review Focus

1. **Tree order and a site column:** sorting the content list by a site column switches it to a flat list (no indent), and "Tree order" brings it back (Task 3, `sorting by a registered column flattens the tree`).
2. **A row action on a record outside the view:** `runRowAction` with an id of another type, another language filter or a trashed record (outside Trash) answers 404 and runs nothing (Task 5).
3. **A card invalid, the entry valid:** creating an entry with a card error writes nothing, shows the card's error and the entry's errors together, and does not redirect (Task 7).
4. **Registration with a card's required field missing:** no user is created, the old input of every field (Hata's and the card's) comes back, the error shows under the card's field (Task 9).
5. **A filter value that is not among its options** (hand-edited `?filter[stock]=x`) is ignored, not passed to `apply` (Task 4).

---

## File Structure

| File | Responsibility |
|---|---|
| `src/Support/Admin/Registry/TargetedRegistry.php`, `TargetScope.php` | Records ops per target, resolves a target into a fresh `Registry`. |
| `src/Support/Admin/Lists/ListContext.php` | What a list entry knows: target, content type, tab. |
| `src/Support/Admin/Lists/{TableColumn,TableColumns,TableColumnRegistry}.php` | Columns. |
| `src/Support/Admin/Lists/{TableFilter,TableFilters,TableFilterRegistry}.php` | Filters. |
| `src/Support/Admin/Lists/{RowAction,RowActions,RowActionRegistry,BulkAction,BulkActions,BulkActionRegistry}.php` | Actions. |
| `src/Livewire/Admin/Concerns/HasListExtensions.php` | The lists' shared trait (columns, filters, actions). |
| `resources/views/partials/admin/lists/*.blade.php` | Header/cell, filters, extra row items, extra bulk items, the generic action confirm modal; Hata's own column cells. |
| `src/Testing/AssertsListQueries.php` | Shipped test helper. |
| `src/Support/Admin/Forms/{FormExtension,FormContext,FormCard,FormCards,FormCardRegistry,FormExtensions}.php` | Form cards. |
| `src/Livewire/Admin/Concerns/HasFormExtensions.php`, `resources/views/partials/admin/forms/cards.blade.php` | Cards in Livewire forms. |
| `src/Support/Content/ContentType.php` (+ form, writer, input, `EntryPage`, site views) | `without()`, `excerpt`, `seo`. |
| `src/Admin.php` | `columns()`, `filters()`, `rowActions()`, `bulkActions()`, `formCards()`. |

---

### Task 1: Targeted registries and the list context

**Files:**
- Create: `src/Support/Admin/Registry/TargetedRegistry.php`, `src/Support/Admin/Registry/TargetScope.php`, `src/Support/Admin/Lists/ListContext.php`
- Create: `tests/fixtures/Registry/FakeTargetedRegistry.php`
- Test: `tests/Feature/Admin/Registry/TargetedRegistryTest.php`

**Interfaces:**
- Consumes: `Registry`, `Entry`, `HasChanges` (release 1).
- Produces:
  ```php
  /** @template TRegistry of Registry */
  abstract class TargetedRegistry {
      /** @param string|list<string> $targets */
      public function on(string|array $targets): TargetScope;   // add/remove/modify recorded for each target, in call order
      /** @return TRegistry */ public function resolve(string $target): Registry; // fresh registry: chain(target) adds wide→narrow, then modifies wide→narrow, then removes
      /** @return list<string> */ public function targets(): array;            // every target that received an op (for hata:admin)
      /** @return list<string> */ public function chain(string $target): array; // 'content.posts' → ['content.*', 'content.@records', 'content.posts']; a taxonomy type → '@taxonomies'; 'users' → ['users']
      abstract protected function newRegistry(): Registry;
  }
  final class TargetScope { add(Entry): static; remove(string): static; modify(string, Closure): static; }
  final readonly class ListContext {
      public function __construct(public string $target, public ?ContentType $type, public string $tab = 'all') {}
      public function isTrash(): bool; public function isTaxonomy(): bool; // false for users
  }
  ```
  Valid targets: `users`, `content.*`, `content.@records`, `content.@taxonomies`, `content.<key>` (key alphabet `[a-z0-9_-]+`); targets are validated when recorded (a bad target is a registration mistake through the base's mistake path). `chain()` reads `ContentTypes` to know whether a key is a taxonomy, at resolve time only. `resolve()` memoizes per target until the next op.

- [ ] **Step 1: Write failing tests** with a fake registry of `FakeEntry`:
  - `an entry added to content.* shows on every content target and not on users`.
  - `a narrower target overrides the same key`: `on('content.*')->add(a label 'wide')`, `on('content.posts')->add(a label 'narrow')` → `resolve('content.posts')->find('a')->label === 'narrow'`, `resolve('content.pages')` → `'wide'`.
  - `a narrower target can remove or modify an inherited entry for itself only`: `on('content.posts')->remove('author')` hides it on posts, not pages; same for `modify`.
  - `group targets`: an entry on `content.@taxonomies` shows on categories (taxonomy) and not on pages.
  - `order of calls does not matter`: narrow `remove` recorded before the wide `add` still hides.
  - `several targets at once`: `on(['users', 'content.*'])->add(...)` shows on both.
  - `a bad target is a registration mistake` (throws in testing; production skips and logs once).
- [ ] **Step 2: Run** `vendor/bin/pest tests/Feature/Admin/Registry/TargetedRegistryTest.php` — FAIL.
- [ ] **Step 3: Implement** as in Interfaces (ops stored as a list of `[target, op, key|entry|closure]`; resolve replays into `newRegistry()` using its public `add`/`modify`/`remove`).
- [ ] **Step 4: Run** the file and `composer types:check` — PASS.
- [ ] **Step 5: Commit** `git commit -m "Add targeted registries and the list context"`

### Task 2: List columns on the users list, and the query helper

**Files:**
- Create: `src/Support/Admin/Lists/TableColumn.php`, `TableColumnRegistry.php`, `TableColumns.php`; `src/Livewire/Admin/Concerns/HasListExtensions.php`; `resources/views/partials/admin/lists/column-header.blade.php`, `column-cell.blade.php`, `resources/views/partials/admin/lists/columns/users/{email,roles,locale,created_at}.blade.php`; `src/Testing/AssertsListQueries.php`
- Modify: `src/Admin.php` (`columns()`), `src/HataServiceProvider.php` (singleton `TableColumns`; Hata's users columns in the `booting` callback), `resources/views/livewire/admin/users/index.blade.php`
- Test: `tests/Feature/Admin/Lists/UserColumnsTest.php`

**Interfaces:**
- Consumes: Task 1.
- Produces:
  ```php
  final readonly class TableColumn implements Entry { use HasChanges;
      public function __construct(
          public string $key, public string $label,
          public Closure|string $value,                 // Closure(Model, ListContext): string|Htmlable|null, or a Blade view name rendered with ['record' => …, 'context' => …]
          public array|Closure $with = [],              // array for ->with(), or Closure(Builder): void (withCount, addSelect)
          public ?Closure $sort = null,                 // Closure(Builder $query, string $direction): void
          public ?Closure $visibleIn = null,            // Closure(ListContext): bool; null = every tab
          public string|Closure|null $permission = null,
          public int $order = 100, public ?string $hideBelow = null /* md|lg */, public string $align = 'start',
      ) {} }
  final class TableColumns extends TargetedRegistry { /** @return list<TableColumn> */ public function for(AdminUser $user, ListContext $context): array; }
  // Admin::columns(string|array $targets): TargetScope  (TableColumns::on)
  trait HasListExtensions {
      abstract protected function listContext(): ListContext;
      /** @return list<TableColumn> */ #[Computed] public function listColumns(): array;
      protected function applyColumnLoads(Builder $query): Builder;           // each visible column's `with`, once
      protected function registeredSort(string $key): ?Closure;              // a visible column's sort closure
  }
  trait AssertsListQueries { protected function assertListQueriesStable(Closure $seed, Closure $render): void; } // seed(int $count) then render(): TestResponse|Testable; counts queries with 1 and with 20 records; fails when they differ
  ```
  Hata's users columns (orders 10/20/30/40): `email` (sortable, `hideBelow: md`), `roles` (`with: ['roles']`, not sortable), `locale` (`hideBelow: lg`), `created_at` (sortable, `hideBelow: lg`). Their cells are today's markup moved into the partials. The fixed first column (user) and the "⋮" column stay in the page. `SORTABLE` becomes `name` plus the keys of visible sortable columns; Hata's `email`/`created_at` columns sort with `orderBy(key, dir)`.

- [ ] **Step 1: Write failing tests:**
  - `the users list renders Hata's columns from the registry in order` (headers in order: user, email, roles, locale, created).
  - `a site hides a Hata column and adds one with its own eager load`: `Admin::columns('users')->remove('locale')`; add `logins` with `with: fn ($q) => $q->withCount('roles')`, value `fn ($u) => (string) $u->roles_count` → header and values shown, locale gone.
  - `a sortable site column sorts the list and an unknown sort falls back`: `?sortBy=logins&sortDirection=desc` orders by the closure; `?sortBy=nope` → default `created_at`.
  - `a column hidden by permission is neither shown nor loaded nor sortable`.
  - `the users list does not grow in queries with rows` using `AssertsListQueries` (with the site column registered).
- [ ] **Step 2: Run** `vendor/bin/pest tests/Feature/Admin/Lists/UserColumnsTest.php` — FAIL.
- [ ] **Step 3: Implement.** The users SFC `use HasListExtensions` (the trait is also used by `EntryListPage` from Task 3, so PHPStan sees it used; until then it is used by no class — if PHPStan reports `trait.unused`, add the content list's `use` in this task without behaviour). `listContext()` = `new ListContext('users', null)`.
- [ ] **Step 4: Run** the file, `tests/Feature/Admin/Users`, `composer types:check` — PASS.
- [ ] **Step 5: Commit** `git commit -m "Add registered list columns, starting with the users list"`

### Task 3: List columns on the content list

**Files:**
- Modify: `src/Livewire/Admin/EntryListPage.php`, `src/Support/Content/EntryListQuery.php`, `resources/views/livewire/admin/content/index.blade.php`, `src/HataServiceProvider.php` (Hata's content columns)
- Create: `resources/views/partials/admin/lists/columns/content/{taxonomies,records,status,author,date,removal}.blade.php`
- Test: `tests/Feature/Admin/Lists/ContentColumnsTest.php`

**Interfaces:**
- Consumes: Tasks 1–2 (`HasListExtensions`, `TableColumn`, `ListContext`).
- Produces: Hata's content columns on `content.*`, orders 10–60: `taxonomies` (visibleIn: the type has taxonomies; eager `terms` stays in `EntryListQuery`), `records` (visibleIn: taxonomy), `status` (visibleIn: not trash and `supports('publication')`), `author` (visibleIn: `supports('author')`), `date` (visibleIn: not trash; sort = today's `date` sort), `removal` (visibleIn: trash). Their cells are today's markup moved into the partials; classes (`max-md:hidden`, `max-lg:hidden`) become `hideBelow`. `EntryListQuery::sort()` accepts a `?Closure $registered` used when the key is not in `SORTS`; such a sort is flat (`isTree()` false). `listContext()` = `new ListContext('content.'.$type->key(), $type, $this->tab)`.

- [ ] **Step 1: Write failing tests:**
  - `the content list renders Hata's columns from the registry` for pages (status, author, date), for a taxonomy (records, no status/author), in trash (removal instead of date, no status).
  - `a site column on content.* shows on every type; on content.posts only on posts`.
  - `content.posts can remove Hata's author column for posts only`.
  - `sorting by a registered column flattens the tree` (Review Focus 1): pages in a tree, `sort('views')` → no `data-depth` indent; `resetSort()` → indent back.
  - `the content list does not grow in queries with rows` with a site column using `with: ['author']` (`AssertsListQueries`).
- [ ] **Step 2: Run** `vendor/bin/pest tests/Feature/Admin/Lists/ContentColumnsTest.php` — FAIL.
- [ ] **Step 3: Implement**; `EntryListPage::sort()` accepts `title`, `date` and the keys of visible sortable columns.
- [ ] **Step 4: Run** the file, `tests/Feature/Admin/Content`, `tests/Feature/Content` — PASS (existing list tests unchanged).
- [ ] **Step 5: Commit** `git commit -m "Draw the content list's columns from the registry"`

### Task 4: List filters

**Files:**
- Create: `src/Support/Admin/Lists/TableFilter.php`, `TableFilterRegistry.php`, `TableFilters.php`, `resources/views/partials/admin/lists/filters.blade.php`
- Modify: `src/Livewire/Admin/Concerns/HasListExtensions.php`, `src/Livewire/Admin/EntryListPage.php`, the two list views, `src/Admin.php` (`filters()`), `src/HataServiceProvider.php` (singleton)
- Test: `tests/Feature/Admin/Lists/ListFiltersTest.php`

**Interfaces:**
- Produces:
  ```php
  final readonly class TableFilter implements Entry { use HasChanges;
      public function __construct(public string $key, public string $label,
          public Closure $options,                       // Closure(ListContext): array<string, string> value ⇒ label, called once per render
          public Closure $apply,                         // Closure(Builder $query, string $value): void
          public ?Closure $visibleIn = null, public string|Closure|null $permission = null, public int $order = 100) {} }
  // HasListExtensions: #[Url(as: 'filter')] public array $filters = []; listFilters(): list<TableFilter>; applyFilters(Builder): Builder
  ```
  Rendered as native `flux:select` (with an "All" empty option) after the list's own filters, `wire:model.live="filters.{key}"`; a change resets the page and the selection. `applyFilters` passes only a value that is a key of the filter's options. `clearFilters()` also empties `filters`; `isFiltered()` counts a non-empty one (content list and users list; the users list gains a "Clear filters" button in its no-results state).

- [ ] **Step 1: Write failing tests:** a filter on `content.pages` narrows the list and is in the query string (`?filter[stock]=out`); `a filter value that is not among its options is ignored` (Review Focus 5: `apply` never called); clear filters empties it; a filter hidden by permission is ignored even when in the URL; the users list filter works the same.
- [ ] **Step 2–4:** run (FAIL), implement, run with `tests/Feature/Admin/Users`, `tests/Feature/Admin/Content` (PASS).
- [ ] **Step 5: Commit** `git commit -m "Add registered list filters"`

### Task 5: Row actions

**Files:**
- Create: `src/Support/Admin/Lists/RowAction.php`, `RowActionRegistry.php`, `RowActions.php`, `resources/views/partials/admin/lists/row-actions.blade.php`, `resources/views/partials/admin/lists/action-confirm.blade.php`
- Modify: `HasListExtensions`, `EntryListPage`, both list views, `src/Admin.php` (`rowActions()`), `HataServiceProvider`
- Test: `tests/Feature/Admin/Lists/RowActionsTest.php`, fixture `tests/fixtures/Lists/ArchiveHandler.php`

**Interfaces:**
- Produces:
  ```php
  final readonly class RowAction implements Entry { use HasChanges;
      public static function link(string $key, string $label, string $icon, Closure $url /* Closure(Model): string */, string|Closure|null $permission = null, int $order = 100, ?Closure $visibleIn = null, bool $newTab = false): self;
      public static function handler(string $key, string $label, string $icon, string $handler /* class-string, __invoke(Model, AdminUser): ?string */, string|Closure|null $permission = null, int $order = 100, ?Closure $visibleIn = null, bool $danger = false, ?string $confirm = null /* translation key */): self;
      public function allows(AdminUser $user, Model $record): bool; // string ability: Gate::forUser($user)->allows($ability, $record); Closure(AdminUser, Model): bool
  }
  // HasListExtensions:
  abstract protected function recordQuery(): Builder;               // the list's own query for one record: same type, language filter, tab (onlyTrashed in Trash)
  public function confirmRowAction(string $key, int $id): void;     // opens the generic confirm modal when the action has `confirm`, else runs
  public function runRowAction(string $key, int $id): void;         // resolves the action visible in this context, loads the record through recordQuery()->whereKey($id)->firstOrFail(), checks allows() again, invokes, toasts the message, forgets the list
  ```
  Default `visibleIn` for row actions: not in Trash. Shown in the "⋮" menu after Hata's items, after a `flux:menu.separator`; danger ones `variant="danger"`. The `permission` closure runs once per row: the docs say it must not query (use eager-loaded data). One shared confirm modal per list for registered actions.

- [ ] **Step 1: Write failing tests:** a link action renders per row with its URL; a handler action runs and toasts its message; a confirmed handler opens the modal first; `a row action on a record outside the view answers 404` (Review Focus 2: other type, a record trashed while on the "all" tab, a language outside the filter) and the handler never runs; a row the user may not act on has no item and `runRowAction` is 403; not shown in Trash by default, shown with `visibleIn: fn ($c) => true` and then loads with `onlyTrashed()`; same on the users list.
- [ ] **Step 2–4:** run (FAIL), implement, run (PASS).
- [ ] **Step 5: Commit** `git commit -m "Add registered row actions to the lists"`

### Task 6: Bulk actions, and selection on the users list

**Files:**
- Create: `src/Support/Admin/Lists/BulkAction.php`, `BulkActionRegistry.php`, `BulkActions.php`, `resources/views/partials/admin/lists/bulk-actions.blade.php`
- Modify: `HasListExtensions`, `EntryListPage`, both list views (the users list gains row checkboxes, a select-page box and the bulk bar, all rendered only when `listBulkActions()` is not empty), `src/Admin.php` (`bulkActions()`), `HataServiceProvider`
- Test: `tests/Feature/Admin/Lists/BulkActionsTest.php`

**Interfaces:**
- Produces:
  ```php
  final readonly class BulkAction implements Entry { use HasChanges;
      public function __construct(public string $key, public string $label, public string $icon, public string $handler /* __invoke(Collection $records, AdminUser $actor): ?string */,
          public string|Closure|null $permission = null, public int $order = 100, public ?Closure $visibleIn = null, public bool $danger = false, public ?string $confirm = null) {} }
  // HasListExtensions: public array $selected (the content list's existing property; the users list adds it, cleared on page, search, filter and sort changes)
  public function confirmBulkAction(string $key): void; public function runBulkAction(string $key): void; // records = recordQuery()->whereKey(selected ids)->get(); one handler call; danger always confirms
  ```
  The selection is page-bound (both lists clear it on a page change), so no chunking: one query, one call. Shown after Hata's bulk buttons in the content list's bar. Default `visibleIn`: not in Trash. The users list keeps its exact markup when no bulk action is visible (tested).

- [ ] **Step 1: Write failing tests:** a bulk action on `content.pages` gets exactly the selected records of the view (an id of another type in `selected` is dropped); a danger action always confirms; the users list shows checkboxes and the bar only with a visible bulk action, and its HTML without one equals today's (no `data-test="bulk-bar"`, no checkbox); not in Trash by default.
- [ ] **Step 2–4:** run (FAIL), implement, run with `tests/Feature/Admin/Users`, `tests/Feature/Admin/Content` (PASS).
- [ ] **Step 5: Commit** `git commit -m "Add registered bulk actions and selection on the users list"`

### Task 7: Form cards on the entry form

**Files:**
- Create: `src/Support/Admin/Forms/FormExtension.php`, `FormContext.php`, `FormCard.php`, `FormCardRegistry.php`, `FormCards.php`, `FormExtensions.php`; `src/Livewire/Admin/Concerns/HasFormExtensions.php`; `resources/views/partials/admin/forms/cards.blade.php`
- Modify: `src/Livewire/Admin/EntryFormPage.php`, `EntryEditPage.php`, `resources/views/livewire/admin/content/create.blade.php`, `resources/views/partials/admin/content/entry-form.blade.php`, `src/Actions/Content/CreateEntry.php`, `UpdateEntry.php`, `resources/js/entry-form.js` only if the guard needs it (the cards' values live in `$wire.extensions`, outside `$wire.form` — include them in `snapshot()`), `src/Admin.php` (`formCards()`), `HataServiceProvider`; `TargetedRegistry` valid targets gain `profile`, `register`
- Test: `tests/Feature/Admin/Forms/EntryFormCardsTest.php`, fixture `tests/fixtures/Forms/SubtitleExtension.php` (+ view in workbench)

**Interfaces:**
- Produces:
  ```php
  abstract class FormExtension {
      /** @return array<string, mixed> */ abstract public function fill(?Model $record, FormContext $context): array;
      /** @return array<string, mixed> */ abstract public function rules(FormContext $context): array;   // keys relative to the card
      abstract public function view(): string;
      /** @param array<string, mixed> $values */ abstract public function save(Model $record, array $values, FormContext $context): void;
  }
  final readonly class FormContext { public function __construct(public string $target, public ?ContentType $type, public ?AdminUser $actor, public bool $creating) {} public function isCreate(): bool; }
  final readonly class FormCard implements Entry { use HasChanges;
      public function __construct(public string $key, public string $extension /* class-string<FormExtension> */, public string|Closure|null $permission = null, public int $order = 100, public string $area = 'main' /* main|side */) {} }
  final class FormCards extends TargetedRegistry { /** @return list<FormCard> */ public function for(FormContext $context): array; } // permission: null = anyone (guests on register); ability/Closure(?AdminUser) checked for the actor
  final class FormExtensions { // stateless service
      public function fill(array $cards, ?Model $record, FormContext $c): array;            // ['key' => values]
      public function rules(array $cards, FormContext $c): array;                           // 'extensions.key.field' => rules
      public function save(array $cards, Model $record, array $values, FormContext $c): void;
      public function field(string $cardKey, string $field, bool $livewire): ComponentAttributeBag; // wire:model="extensions.k.f" or name="extensions[k][f]" + value old()
  }
  trait HasFormExtensions { public array $extensions = []; protected function formContext(): FormContext; protected function mountExtensions(?Model $record): void; protected function extensionErrors(): array /* validator errors, no throw */; protected function saveExtensions(Model $record): void; }
  // CreateEntry::handle(..., ?Closure $alsoSave = null) and UpdateEntry::handle(..., ?Closure $alsoSave = null): called with the saved Entry inside the action's transaction
  ```
  Card view receives `$key`, `$field` (a `Closure(string): ComponentAttributeBag` from `FormExtensions::field`), `$errorName` (`Closure(string): string`, the dot path for `<flux:error>`), `$values`. `cards.blade.php` renders each card in the entry form's `$card` frame, by area. Validation: the page computes the cards' errors first (no throw); if any, it also runs the entry's own validation (`EntryInput::validate(...)`, no write) and shows both bags together through `showErrors()`; nothing is written. Else it calls the action with `alsoSave: fn (Entry $e) => $this->saveExtensions($e)`. Target `content.<type key>`; area `side` goes to the sidebar after Hata's cards.

- [ ] **Step 1: Write failing tests:** a card renders in main and one in side; on create, `save()` receives the new entry inside the transaction (assert `DB::transactionLevel() > 0` from the fixture) before the redirect; on edit the values persist and the page stays; `a card invalid, the entry valid writes nothing` (Review Focus 3: no entry row, no redirect, the card's error and an entry error (empty title) both shown); an exception in `save()` rolls the entry back (no row); a card hidden by permission neither renders nor saves even if `extensions.k` is posted.
- [ ] **Step 2–4:** run (FAIL), implement, run with `tests/Feature/Admin/Content`, `tests/Feature/Actions/Content` (PASS).
- [ ] **Step 5: Commit** `git commit -m "Add form cards to the entry form"`

### Task 8: Form cards on the user form

**Files:**
- Modify: `resources/views/livewire/admin/users/create.blade.php`, `edit.blade.php`, `resources/views/partials/admin/user-form.blade.php`, `src/Actions/Users/CreateUser.php`, `UpdateUser.php` (`?Closure $alsoSave = null`, called with the user inside their transaction)
- Test: `tests/Feature/Admin/Forms/UserFormCardsTest.php`, fixture `tests/fixtures/Forms/PersonalExtension.php` (birthday + gender, rules differ by target: required on `register`)

**Interfaces:**
- Consumes: Task 7. Target `users`; area `main` only (a `side` card is shown in main). Validation: `UserForm`'s validation errors and the cards' errors are merged and shown together; nothing saved when any fails.

- [ ] **Step 1: Write failing tests:** create and edit save the card's values (a migration in the test adds `birthday`/`gender` to the workbench users table via `Schema::table` in `beforeEach`); a card error + valid user creates nothing; `ProtectedActionException` from `UpdateUser` leaves the card's values unsaved.
- [ ] **Step 2–4:** run (FAIL), implement, run with `tests/Feature/Admin/Users` (PASS).
- [ ] **Step 5: Commit** `git commit -m "Add form cards to the user form"`

### Task 9: Form cards on the profile page and registration

**Files:**
- Modify: `resources/views/livewire/settings/profile.blade.php` (cards after email; user save + cards in one `DB::transaction`), `resources/views/auth/register.blade.php` (cards after email, plain `name` fields), `src/Actions/Fortify/CreateNewUser.php` (one `Validator` with Hata's and the cards' rules; `DB::transaction` around create, default role and the cards' `save()`)
- Test: `tests/Feature/Settings/ProfileCardsTest.php`, `tests/Feature/Auth/RegistrationCardsTest.php`

**Interfaces:**
- Consumes: Task 7 (`FormExtensions::field(..., livewire: false)` for registration; `FormContext('register', null, null, true)`), Task 8's fixture registered on `['users', 'profile', 'register']`.

- [ ] **Step 1: Write failing tests:** the profile saves the card's values with name/email and a card error saves nothing; registration (turned on as the existing registration tests do) creates the user with the card's values and the default role; `registration with a card's required field missing creates no user` (Review Focus 4: old input of name, email and the card's fields come back, the error is under the card's field); a card with permission `'users.view'` is not on the registration form (a guest has no abilities).
- [ ] **Step 2–4:** run (FAIL), implement, run with `tests/Feature/Auth`, `tests/Feature/Settings` (PASS).
- [ ] **Step 5: Commit** `git commit -m "Add form cards to the profile page and registration"`

### Task 10: Content type parts that can be switched off

**Files:**
- Modify: `src/Support/Content/ContentType.php`, `resources/views/partials/admin/content/entry-form.blade.php` (excerpt textarea gated by `excerpt`, SEO card by `seo`), `src/Support/Content/EntryInput.php` (rules only for supported parts), `src/Actions/Content/EntryWriter.php` (an unsupported part keeps its stored value), `src/Support/Content/EntryPage.php` (description: SEO only if `seo`, excerpt only if `excerpt`, then body), `resources/views/site/term.blade.php`, `resources/views/partials/site/record-cards.blade.php` (excerpt only if supported), `resources/js/entry-form.js` only if the preview breaks without the fields, `src/Console/Commands/MakeContentType.php` (writes an empty `without()` with a comment listing the parts)
- Test: `tests/Feature/Content/ContentTypePartsTest.php`, fixture `tests/fixtures/Content/LeanPostsType.php` (extends `PostsType`, adds a `Text` field, `without()` returns `['author', 'excerpt']`)

**Interfaces:**
- Produces: `protected function without(): array` (default `[]`); `supports(string $feature): bool` = for a taxonomy `in_array($feature, ['excerpt', 'seo'])` and not in `without()` (terms keep today's excerpt and SEO); otherwise not in `without()`. Parts: `body`, `image`, `publication`, `author`, `excerpt`, `seo`.

- [ ] **Step 1: Write failing tests:** the subclass listed in place of `PostsType` keeps key `posts`, its records and permissions, shows its extra field and no author/excerpt; for each part switched off alone (dataset over the six), the create form, the list, the public page and the head render without error, and saving keeps an existing stored value of that part; with `seo` off the description comes from the excerpt; `make:content-type` writes `without()`.
- [ ] **Step 2–4:** run (FAIL), implement, run with `tests/Feature/Content`, `tests/Feature/Admin/Content`, `tests/Feature/Console` (PASS).
- [ ] **Step 5: Commit** `git commit -m "Let a content type switch parts of its form off"`

### Task 11: hata:admin for targets, the stub, docs, decision

**Files:**
- Modify: `src/Console/Commands/AdminRegistriesCommand.php` (for each targeted registry: each target that received an op, a table of its resolved entries, plus unmatched), `stubs/hata-admin-service-provider.stub` (commented examples of each release-2 point, imports added), `docs/specs/admin-extensions.md` (targets, list context, each new registry with Hata's own keys and orders, cost rules, the test helper, `FormExtension` with the birthday/gender example and where to store fields (columns on `users` for the site, a module's own table), `without()`), `docs/specs/content.md`, `docs/specs/users.md`, `docs/specs/user-settings.md`, `docs/specs/package.md` ("What changed for v0.5.0": the lists' columns are registry entries — a site that overrode nothing is unaffected; `supports()` gained `excerpt` and `seo`; a type subclass overriding `supports()` should move to `without()`), `CLAUDE.md` (decision 83, Specs/Package structure lines)
- Test: `tests/Feature/Console/AdminRegistriesCommandTest.php` (columns on `users` and `content.posts` listed), the stub test (imports real classes, uncommented copy lints)

- [ ] **Step 1: Write the failing command test; Step 2: run (FAIL); Step 3: implement and write the docs; Step 4:** `composer test`, then the MySQL and PostgreSQL runs (one at a time) — all green.
- [ ] **Step 5: Commit** `git commit -m "Document the release 2 extension points"`
- [ ] **Step 6: Browser pass with the user** in the demo switched to the branch (path repository, back up `composer.json`/`composer.lock`, `php artisan vendor:publish --tag=hata-assets --force` after a `dist/` rebuild): a site column and filter on both lists, a row and a bulk action, the users list with and without selection, a card on the entry form (main and side), the user form, the profile page and registration. Fix what they find; merge on their word, push, tag `v0.5.0`, demo back to Packagist `^0.5`.

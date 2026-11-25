# Admin extension points

2026-11-25. Follows v0.3.0. Two releases: **v0.4.0** (the common base, the top bar, collapsible menu groups,
dashboard widgets) and **v0.5.0** (list columns, filters, row and bulk actions, form cards, content type fields).

## Intent

Hata is installed into client sites and will carry modules (a future `hata-shop`). The user wants everything visual
in the admin to be addable from the site or a module, and some of it hideable, **without ever editing or overriding a
Hata file**, so that `composer update` keeps bringing fixes. Their words for this release: the architecture must be
extensible and flexible, and making a change by hand must be easy and logical.

Two registries exist already (`AdminMenu`, `SettingsSections`) and each re-implements the same things: a key, an
order with ties in insertion order, a permission that is an ability or a closure, and "a registration mistake throws
in local/testing, is logged once elsewhere". Five more extension points copying that again would make each one
behave slightly differently. So this design starts with one shared base, and every extension point, old and new, is
the same shape.

## Goals

1. **One pattern for every extension point.** Each point is a registry of entries; every entry has a `key`, an
   `order` and a `permission`; every registry has the same verbs (`add`, `remove`, `modify`, `has`, `find`, `for`).
   Learning one teaches all of them.
2. **One obvious place to write it.** A single entry point `Hata\Admin` with a method per extension point, and a
   provider in the site written by the installer (`app/Providers/HataAdminServiceProvider.php`) where a developer
   opens one file and sees commented examples of each point.
3. **Hata's own parts use the same API.** Hata's menu items, settings sections, dashboard widgets, top bar item and
   list columns are registered through the registries, so a site can reorder or hide them by key, and Hata's code is
   the first example of the API.
4. Release 1: a desktop top bar, collapsible menu groups, dashboard widgets (Hata ships two).
5. Release 2: list columns with sorting, list filters, row and bulk actions, form cards, and content type fields
   that can be extended and hidden.
6. **Nothing an extension adds costs more than it says.** Registries make no queries; a column or filter costs at
   most one query per page, never one per row; dashboard widgets load lazily.

## Non-goals

- Hata's own domain events (`EntryPublished`…); Eloquent's model events exist. Later, when a module needs them.
- Replacing a whole Hata page by name. A last resort, later.
- Sections in the per-user settings (`/settings/*`).
- Changing Hata's own row actions (trash, restore, publish…) or their confirm dialogs: a site **adds** actions, it
  does not replace Hata's.
- A visual editor for any of this in the admin. It is code, in the site's provider.

## Anti-goals

- A site edits or overrides a Hata file to add something. If a test or a doc example needs that, the design failed.
- An extension point that is fast only when used carefully. A column that runs a query per row must be impossible
  through the API (it declares its eager loads), and a test helper lets a module prove its list does not grow in
  queries with rows.
- A registration mistake that takes down a production admin. It throws where a developer sees it and is skipped
  and logged once elsewhere, as today.
- Breaking existing code: `app(AdminMenu::class)->add(new AdminMenuItem(...))` and
  `app(SettingsSections::class)->add(...)` keep working unchanged.

## Approaches (chosen with the user)

- **A, chosen:** one typed registry per extension point, like `AdminMenu`/`SettingsSections`, now on a shared base.
  Typed entries give IDE completion and PHPStan checks; each registry knows its own rules (a column declares its
  eager loads, a widget its width).
- B, rejected: Blade slots / stacks in Hata's views (`@stack('admin.users.columns')`). A slot cannot eager-load or
  sort, cannot be hidden or reordered by key, and binds a site to Hata's markup, so a markup change breaks it.
- C, last resort only: replacing a whole Hata page. The site then stops receiving that page's fixes.

## Design

### 1. The common base (release 1)

`Hata\Support\Admin\Registry\Registry<TEntry>` (abstract) holds what `AdminMenu` and `SettingsSections` repeat
today:

| Method | Meaning |
|---|---|
| `add(TEntry $entry)` | Adds; the same key replaces the earlier entry and keeps its insertion position for ties. |
| `remove(string $key)` | Removes (hides) an entry, Hata's or anyone's. Removing a key that is not there is a no-op. |
| `modify(string $key, Closure $change)` | `fn (TEntry $entry): TEntry`; changes an existing entry (most often its order, label or permission) without restating it. A missing key is a registration mistake. Applied when the registry is read, so it may be called before the entry is added (provider order does not matter). |
| `has(string $key)`, `find(string $key)` | Lookup. |
| `all()` | Every entry by `order`, ties in insertion order, `modify` applied. |
| `for(AdminUser $user)` | `all()` filtered by permission and by the registry's own validity check. |

Entries are `final readonly` classes implementing `Hata\Support\Admin\Registry\Entry`
(`key(): string`, `order(): int`, `permission(): string|Closure|null`). Each entry class has `with(...)` (named
arguments, returns a copy), which is what a `modify` closure calls:

```php
Admin::menu()->modify('media', fn (AdminMenuItem $item) => $item->with(order: 5));
Admin::dashboard()->remove('recent-changes');
```

Shared rules, written once in the base:

- **Permission:** `null` = every admin who reaches the page; a string = a gate ability (`Gate::forUser($user)`); a
  `Closure(AdminUser): bool`. Row actions also receive the record (below).
- **Order:** an int; Hata's own entries use steps of 10 (10, 20, 30…), so a site slots between them (`15`). The
  spec of each point lists Hata's keys and orders in a table.
- **Keys:** lowercase words joined by hyphens or dots (`^[a-z0-9]+([.-][a-z0-9]+)*$`); a module prefixes its keys
  (`shop-orders`). Dots are allowed because the content menu items are already keyed `content.<type>` and a site may
  have `remove('content.posts')` today. A bad key is a registration mistake.
- **`modify` or `remove` of a key that is not there is not a mistake:** it is skipped, because some entries appear
  only at read time and depend on data (a content type switched off in the settings removes its menu item; a lazy
  provider adds nothing). `php artisan hata:admin` lists every `modify`/`remove` that matched nothing, so a typo is
  still easy to find. (Builder check; my call.)
- **Registration mistakes** (bad key, a component Livewire cannot resolve, a route that
  is not defined, a column with no value): `LogicException` naming the registry and the key in `local`/`testing`;
  elsewhere the entry is skipped and one warning per message per process is logged. One method,
  `Registry::problem()`.
- **No work at boot:** registering stores objects; nothing is resolved (routes, components, translations) until a
  page reads the registry.

`AdminMenu` and `SettingsSections` move onto the base. Their public API stays (`group()`, `lazy()`,
`visibleTo()`, `open()`, `pick()`, `ALIASES`); they gain `modify`/`has`/`find`. `AdminMenuItem` and
`SettingsSection` gain `with()`.

Registries that are **per list or per form** (columns, filters, row actions, bulk actions, form cards) are keyed by
a *target*: `users`, `content.<type>` (one type) and `content.*` (every content type, taxonomies included;
`content.@taxonomies` and `content.@records` narrow it). Reading `content.posts` merges `content.*`, the matching
group target and `content.posts`; the more specific target wins on the same key. So "a column on every content list"
and "a column only on products" are both one line.

A narrower target can also `remove` or `modify` an entry inherited from a wider one, for that target only:
`Admin::columns('content.posts')->remove('author')` hides Hata's `author` column on posts and nowhere else. Resolution
on read, whatever order providers ran in: take the wider targets' entries (`content.*`, then the group), overlay the
narrower target's own `add`s, then apply `modify`s from wide to narrow, then `remove`s from any matching target.
(Builder check; my call.)

**List context.** Every list-bound entry (column, filter, row action, bulk action) receives a `ListContext`
(`target`, the `ContentType` or null for users, `tab`: `all`/`published`/`draft`/`trash`…, `isTrash()`,
`isTaxonomy()`). Its `permission` closure may take it as a last argument, and each entry has
`visibleIn: ?Closure(ListContext): bool`. Defaults: **a site's columns show in every tab; its row and bulk actions
never show in Trash** unless `visibleIn` says so, and a handler in Trash loads its records with `onlyTrashed()`
through the list's query, so a module's action never runs on a trashed record by accident. Hata's own columns use
`visibleIn` for what the list does by hand today (trash shows the removal date instead of the edit date, taxonomies
show the record count instead of status and author, the terms column only for types with taxonomies). `value` takes
`(Model $record, ListContext $context)`. (Builder check; my call.)

### 2. One entry point and the site's provider (release 1)

`Hata\Admin` (a plain class with static methods, resolving the singletons; not a Laravel facade, so there is
nothing to alias and PHPStan sees the real types):

```php
Admin::menu();                     // AdminMenu
Admin::topbar();                   // AdminTopbar
Admin::dashboard();                // DashboardWidgets
Admin::settings();                 // SettingsSections
Admin::assets();                   // AdminAssets
Admin::columns('users');           // release 2: TableColumns for a target
Admin::filters('content.posts');   // release 2
Admin::rowActions('content.*');    // release 2
Admin::bulkActions('users');       // release 2
Admin::formCards('content.posts'); // release 2
```

`app(AdminMenu::class)` keeps working; `Admin::` is the documented way.

`hata:install` writes `app/Providers/HataAdminServiceProvider.php` from a stub and adds it to
`bootstrap/providers.php` (by its skeleton shape, printing by-hand lines when the file does not match, as for the
other host edits). Its `boot()` holds one commented example per extension point, in the order of this document, so a
developer opening it sees what can be done and copies a line. Already-installed sites get the file by running
`php artisan hata:install` again (it skips what is done) or by copying the stub; the release notes say so. A module
registers in its own provider's `boot()` the same way.

`php artisan hata:admin` prints what is registered, per registry (and per target for the release-2 registries):
key, order, permission, the class or component, and whether `modify`/`remove` touched it. It is the "why doesn't my
item show" tool. Read-only, no queries.

`php artisan make:dashboard-widget Orders` writes a widget class and its view into the site and prints the line to
add in `HataAdminServiceProvider` (it does not edit the provider: a printed line is easier to place by hand than a
generated edit is to review).

### 3. Top bar (release 1)

Desktop (`lg` and up) gets a top bar across the content area, in `layouts/app/sidebar.blade.php`, the height of the
sidebar header (`h-16`, `border-b border-sidebar-border`). Today there is none; the mobile `flux:header` stays.

- `AdminTopbar` registry of `TopbarItem` entries, two kinds:
  - **Link:** `TopbarItem::link(key, label, icon, route|url, routeParameters = [], newTab = false, permission, order, side = 'end')`.
  - **Component:** `TopbarItem::component(key, component, permission, order, side = 'end')`: a Blade component name
    (`'shop::topbar-cart-status'`) rendered with no attributes. Something that needs live data is a Livewire
    component placed inside that Blade component, at its own cost.
- `side`: `start` (after the page's left edge) or `end` (right). Ordered within each side.
- Hata registers one entry: `view-site` (`end`, order 100, new tab, `arrow-top-right-on-square`, the home route). The
  "View site" link at the bottom of the sidebar is removed on desktop and stays for mobile (`lg:hidden`), since the
  top bar is desktop-only. My call: a top bar on a phone would take a second header row.
- Cost: no query. Routes resolve as the menu does (a missing route is a registration mistake).

```
┌ sidebar ──────┬──────────────────────────────────────────────────────────┐
│ ⌂ Hata        │ [start items]                  [shop: 3 new] [↗ View site] │
│               ├──────────────────────────────────────────────────────────┤
│ Content  ˅    │  page                                                     │
```

### 4. Collapsible menu groups (release 1)

- `AdminMenu::group(key, label, order, collapsible = false)`. A collapsible group renders as
  `<flux:sidebar.group expandable>` (Flux free has `expandable`).
- The open/closed state is kept per browser in `localStorage` (as the media folder tree, decision 47), keyed by
  the group key; a group holding the current page is always open on load.
- Hata's own groups: `content` and `administration` collapsible, `platform` and `account` not (one or two items).
- `AdminMenu::modifyGroup(key, fn (array $group) => [...])` lets a site flip `collapsible` or the order of Hata's
  groups, matching `modify` for items.
- Cost: none on the server.

### 5. Dashboard widgets (release 1)

- The dashboard (`admin.dashboard`, today a static placeholder view) becomes a Blade view that renders the widgets
  the user may see in a 6-column grid (`third` = 2, `half` = 3, `full` = 6 columns on `lg`; every widget full width
  below `md`). With no widget visible, an empty state (`<x-hata::admin.empty-state>`) says so.
- `DashboardWidgets` registry of `DashboardWidget` entries: `key`, `component` (Livewire component name or class),
  `permission`, `width` (`third`|`half`|`full`, default `third`), `order`.
- `Hata\Livewire\Admin\Widget` is the base class of a widget component: it renders a card with the title
  (`title(): string`) and a placeholder of the card's shape while loading, and the subclass gives the body view.
  Every widget is loaded lazily and the loads are bundled into one request (Livewire 4 `lazy.bundle`), so the page
  shows at once and N widgets cost one extra request, not N.
- A widget that fails to render shows "This widget could not be loaded" in its card (and is logged) in production;
  in local/testing the exception surfaces. One broken module widget must not blank the dashboard.
- Hata ships two:
  - `content` ("Content", `third`, order 10): records per active type the user may view (published / drafts),
    one grouped query, each row links to the type's list. Permission: any of the types' `view`.
  - `recent-changes` ("Recent changes", `half`, order 20): the 8 records last edited among the types the user may
    view, one query (with the type and author eager-loaded), each linking to its edit page.
- A site hides (`remove`), reorders (`modify`) or replaces (`add` with the same key) Hata's widgets; the user said
  yes to that.

### 6. List columns (release 2)

Targets `users` and `content.*` / `content.<type>`.

- `TableColumns` registry of `TableColumn` entries:
  - `key`, `label`, `order`, `permission`;
  - `value`: `Closure(Model $record): string|Htmlable|null` or a Blade view name rendered with `['record' => …]`;
  - `with`: what to eager-load, an array for `$query->with()`, or `Closure(Builder $query): void` for anything
    else (`withCount`, `addSelect` of a subquery). Applied once to the list's query: the column costs at most one
    query per page;
  - `sort`: `null` (not sortable) or `Closure(Builder $query, string $direction): void`;
  - `hideBelow`: `null`, `md` or `lg` (the `max-md:hidden` pattern the lists use); `align`: `start`|`end`.
- **Hata's own columns become registered entries** (users: `email`, `roles`, `locale`, `created_at`; content:
  `status`, `author`, `updated_at`, and the others the list shows today), so a site hides or reorders them. The first
  column (the name or title with its link, tree indent, language codes and badges) and the "⋮" column are fixed: they
  carry the list's own behaviour.
- **Sorting:** a sortable column joins the list's whitelist through its key (the users list's `SORTABLE`, the
  content list's `EntryListQuery::sort()`); an unknown key falls back to the default sort as today. Sorting by a key
  of the JSON `fields` column is not offered out of the box (decision 57); the docs show the cost and the way out (a
  real column or a table of the type's own). A sortable column on the content list switches a tree list to flat,
  as sorting by a built-in column does today.
- **Test helper:** `Hata\Testing\AssertsListQueries` (a trait for Pest/PHPUnit) renders a list with 1 and with 20
  records and fails when the query count differs. Hata's own list tests use it; a module's tests use it for its
  columns.

### 7. List filters (release 2)

- `TableFilters` registry of `TableFilter` entries: `key`, `label`, `order`, `permission`,
  `options: Closure(): array<string, string>` (value ⇒ label, called once per render), and
  `apply: Closure(Builder $query, string $value): void`.
- Rendered as a native `flux:select` next to the list's existing filters; the chosen value lives in the page's
  query string (`?filter[stock]=out`) so a filtered list can be linked; a value that is not among the options is
  ignored. "Clear filters" clears them with Hata's.
- The docs say a filter must hit an indexed real column (as sorting); the test helper covers filters too.

### 8. Row and bulk actions (release 2)

- `RowActions` registry of `RowAction` entries, shown in the "⋮" menu after Hata's own items, separated by a
  divider:
  - **Link:** `RowAction::link(key, label, icon, url: Closure(Model): string, permission)`, e.g. "Orders of this
    user" to a module's page;
  - **Handler:** `RowAction::handler(key, label, icon, handler: class-string, permission, danger = false, confirm = null)`.
    The handler is an invokable class `__invoke(Model $record, AdminUser $actor): ?string` (an optional success
    message for the toast). The list page runs it through one generic method (`runRowAction(key, id)`) that loads
    the record through the list's own query (so a row the user cannot see cannot be acted on), checks the permission
    again, and shows the toast. `confirm` is a translation key: the action then goes through
    `<x-hata::confirm-modal>` first.
  - `permission` here is an ability or `Closure(AdminUser, Model): bool`.
- `BulkActions` registry of `BulkAction` entries, in the bulk bar after Hata's: `handler` gets
  `(Collection $records, AdminUser $actor): ?string`, records loaded through the list's query, chunked by the list's
  page size; `confirm` as above. A bulk action with `danger` always confirms.
- **The users list gets selection** (checkboxes, select page, the bulk bar, as the content list), because the user
  asked for bulk actions in both lists. Hata registers no bulk action for users, so the checkboxes and the bar render
  only when at least one bulk action is visible to the user; a site without any sees the list exactly as today.

### 9. Form cards (release 2)

Targets `content.*` / `content.<type>` (the entry form: create and edit) and `users` (the user form).

**A card is part of the form, not a component of its own** (the user's choice, after the builder check showed that
the create pages and the user pages redirect after Save, so a separate component could neither save in time nor stop
the save).

- `FormCards` registry of `FormCard` entries: `key`, `permission`, `order`, `area` (`main`, below Hata's fields, or
  `side`, the entry form's sidebar; the user form has `main` only), and `extension`: a class extending
  `Hata\Support\Admin\Forms\FormExtension`:
  - `fill(?Model $record, FormContext $context): array`: the card's initial values (on create `$record` is null);
  - `rules(FormContext $context): array`: validation rules for its own keys;
  - `view(): string`: a Blade view rendered inside Hata's card frame, binding its inputs with
    `wire:model="extensions.<key>.<field>"` (the view receives `$key` to build that prefix); interactivity is Alpine
    only, no Livewire calls of its own;
  - `save(Model $record, array $values, FormContext $context): void`: stores the values; called **inside the form's
    transaction, after the record is saved and before the redirect**, so on create it receives the new record.
  - `FormContext`: target, content type (or null), the actor, `isCreate()`.
- The form page holds every visible card's values in one array property `extensions` (keyed by card key), validates
  them with its own rules in the same `validate()` (errors show under the card's fields; any error stops the whole
  save), and calls each card's `save()` in the transaction. An exception in `save()` rolls back the record too. The
  unsaved-changes guard covers the cards, because their inputs are in the form's state.
- Cost: `fill()` runs once on mount; a card that reads data must do it in one query (the record is passed, so a
  relation is one query). Documented, and the list test helper's sibling `AssertsFormQueries` checks the form's
  mount does not grow with cards.
- Hata's own form blocks stay as they are (not entries): they carry the form's binding, guard and Save.

**The same extension on the user's own profile and on registration** (added after approval, at the user's
question: "birthday, gender on the profile page, and the same on registration if needed"):

- Two more targets for `FormCards`: `profile` (the Livewire page `/settings/profile`, `hata::settings.profile`,
  the signed-in user editing themselves) and `register` (Fortify's plain Blade form `hata::auth.register`, posted to
  `register.store` and handled by `Hata\Actions\Fortify\CreateNewUser`). One extension class can be registered on
  `users`, `profile` and `register` at once (`Admin::formCards(['users', 'profile', 'register'])->add(...)`), so
  "birthday" is written once.
- `FormContext` gains `target`, so `rules()` and `save()` can differ (e.g. required on registration, optional for an
  admin), and `permission` on `profile`/`register` is usually `null` (the user themselves; a guest on `register`,
  where the permission closure receives null as the actor).
- The view must work in both kinds of form: Hata passes it a `$field` helper (`$field('birthday')` returns the
  attributes: `wire:model="extensions.<key>.birthday"` in a Livewire form, `name="extensions[<key>][birthday]"` and
  the old input value in the plain registration form) and the error bag key, so one view serves all three. A
  `flux:input` / `flux:select` / `flux:radio.group` with `{{ $field('gender') }}` is the documented example.
- `CreateNewUser` validates the extensions' rules together with its own and calls their `save()` in one transaction
  with creating the user (today it has none; one is added), so a failed extension leaves no half-registered user.
  The profile page validates and saves them with `updateProfileInformation()` the same way.
- The profile page and registration are in release 2 with the other form cards.

**Where a user's extra fields are stored** (the user's question; my recommendation, in the docs):

- **The site's own fields → columns on `users`.** The users table and the `User` model belong to the site (decision
  71: Laravel's users migration is the host's, Hata only adds its columns in its own migration), so the site adds a
  migration and a cast or fillable on its own model. No extra query, and the column can be indexed, sorted and
  filtered in the users list (sections 6 and 7 need real columns).
- **A module's fields → its own table** (`shop_customers` with a unique `user_id`, a `hasOne` the module adds through
  `resolveRelationUsing`). A module never alters `users`: two modules could collide on a column name, and removing a
  module must leave the site's table as it was.
- **No generic profile table or JSON column in Hata.** It cannot be indexed or sorted (the reason of decision 57) and
  would be a second place for the same data.

### 10. Content type fields (release 2)

- **Extend and hide a kit type by subclassing it**, listed in `config/hata.php` → `content.types` in place of the
  kit class (same key, so records, permissions and addresses are unchanged). This works today; release 2 documents it
  and tests it (a subclass of `PostsType` adding a field and dropping one).
- **Switching parts of the common form:** `supports()` gains `excerpt` and `seo` next to `body`, `image`,
  `publication` and `author`. A type says what it does not use in one method:

  ```php
  protected function without(): array { return ['author', 'excerpt']; }
  ```

  `supports($feature)` is `! in_array($feature, $this->without())` (taxonomies keep their fixed list). Tests check
  that the form, the list, the public page and the head (`PageMeta`: with no `seo`, the description falls back to
  the excerpt, then the site's) work with any one part off.
- `make:content-type` writes an empty `without()` with a comment listing the parts.

## Constraints

- PHP ^8.4, Laravel 13, Livewire 4 (SFC pages; the base classes live in `src/Livewire/Admin` because PHPStan does not
  analyse SFC files, decision 32), Flux **free** only.
- Every new label in both `resources/lang/en` and `uk` (`hata::admin.*`); the parity test.
- A site's extension never needs `npm`: anything visual it adds uses Flux and Tailwind classes Hata's build already
  contains, or its own assets through `Admin::assets()`. The docs say so; the top bar, widget card and form card
  frames come from Hata.
- Every section's spec updates in the same change (`docs/specs/admin-menu.md`, `package.md`, `content.md`,
  `users.md`, a new `docs/specs/admin-extensions.md` holding the base, the entry point and each registry's table of
  Hata's own keys), and CLAUDE.md gets a decision per release.
- Release 1 is v0.4.0 (minor: the dashboard view changes, the sidebar's "View site" moves); release 2 is v0.5.0.

## Left to the builder

- Class and file names inside `src/Support/Admin` beyond the public ones named here, and how the base stores
  pending `modify` closures.
- The exact placeholder and card markup (within the admin's look: cards, `bg-sidebar`/zinc/accent utilities).
- Whether the content list's existing filters (language, term) become registered entries internally; the public
  surface above does not depend on it.
- The ordering numbers of Hata's existing columns and widgets, as long as they are steps of 10 and listed in the spec.

# Admin extension points

> The admin address is `config('hata.admin.path')`; `/admin` stands for it. Design: `docs/superpowers/specs/2026-11-25-admin-extension-points-design.md`; the reasons are decision 81 in `CLAUDE.md`.

A site or a module adds, reorders, changes and hides parts of the admin **without editing or overriding a Hata file**, so `composer update` keeps bringing fixes. Hata's own parts are registered through the same API, so a site can reorder or hide any of them by key.

Release 1 (v0.4.0) covers four registries: the sidebar menu, the desktop top bar, the dashboard widgets and the site settings sections. Admin assets (`AdminAssets`) stay as they were. Release 2 (planned): list columns, filters, row and bulk actions, form cards and content type fields; see the design.

## The shared base

`Hata\Support\Admin\Registry\Registry` is the base of every registry (`AdminMenu`, `AdminTopbar`, `DashboardWidgets`, `SettingsSections`); each is a singleton. Entries are `final readonly` classes implementing `Hata\Support\Admin\Registry\Entry` (`key`, `order`, `permission`) and using `HasChanges`, which gives `with(...)`: a copy with some constructor arguments changed.

| Method | Meaning |
|---|---|
| `add($entry)` | Adds an entry. The same key replaces the earlier entry and keeps its place among equal orders. |
| `remove($key)` | Hides the key for good, whether its entry is added before or after the call (Hata's, a module's or a lazy one). To replace an entry, `add()` one with the same key. |
| `modify($key, fn ($entry) => $entry->with(...))` | Changes an entry. Applied when the registry is read, in the order the calls were made, so it may precede the `add()` of the entry. |
| `has($key)`, `find($key)` | Whether the key is registered and not removed; the entry with its modifications, or null. |
| `all()` | Every valid entry by `order`, ties in insertion order, removals and modifications applied. |
| `entriesFor($user)` | `all()` limited to what the user may see (the top bar, the dashboard and the settings sections wrap it as `for($user)`; the menu's `for($user)` returns its groups instead). |

```php
Admin::menu()->modify('media', fn (AdminMenuItem $item) => $item->with(order: 5));
Admin::dashboard()->remove('recent-changes');
```

**Permission.** `null` means everyone who reaches the page; a string is a gate ability (`Gate::forUser($user)`); a `Closure(AdminUser): bool` decides itself. Hata's admin menu items also need `admin.access`; a module's should too.

**Order.** An integer; lower comes first, ties keep insertion order. Hata's own entries use steps of 10 (the tables below), so a site slots between them with `15`.

**Keys.** Lowercase letters and digits joined by hyphens or dots (`^[a-z0-9]+([.-][a-z0-9]+)*$`); a module prefixes its keys (`shop-orders`). Two registries widen this: admin menu items also allow underscores and hyphens anywhere in a segment (`[a-z0-9_-]+` joined by dots), because content type keys have that alphabet (`content.<type>`); settings section keys stay hyphens-only, because they are `?tab=` values.

**`modify()` and `remove()` of a key that is not there are skipped, never a mistake.** Some entries exist only at read time and depend on data (a content type switched off in the settings has no menu item; a lazy provider may add nothing). `php artisan hata:admin` lists every call that matched nothing, so a typo is still easy to find.

**Registration mistakes** (a bad key, an undefined route, a component Livewire cannot resolve, an unknown width or side, an item in an undeclared menu group) throw a `LogicException` naming the registry and the key in `local` and `testing`, so a module's own tests catch them. Elsewhere the entry is left out and one warning per message per process is logged. The menu checks the routes of every item, not only of those the user sees, so a mistake shows to the developer whoever is signed in.

A `modify()` closure must return an entry of the same class with the same key (`$entry->with(...)` does). Anything else (another key, null, another type) is a registration mistake: it throws in `local` and `testing`; elsewhere the entry stays as it was and one warning is logged ("left unchanged").

**No work at boot.** Registering stores objects; nothing (routes, components, translations) is resolved until a page reads the registry, and the registries make no queries.

**Provider order.** `HataServiceProvider::register()` registers Hata's own entries in an application `booting` callback, which runs before any provider's `boot()`. A site's or module's `boot()` therefore always meets Hata's entries, and `remove()` and `modify()` work in any call order.

## `Hata\Admin` and the site's provider

`Hata\Admin` is a plain class with static methods that resolve the singletons (not a facade, so PHPStan and the IDE see the real types): `Admin::menu()`, `Admin::topbar()`, `Admin::dashboard()`, `Admin::settings()`, `Admin::assets()`. `app(AdminMenu::class)` and the other old spellings keep working.

`php artisan hata:install` writes `app/Providers/HataAdminServiceProvider.php` from `stubs/hata-admin-service-provider.stub` and lists it in `bootstrap/providers.php`. It is the site's file: every example in its `boot()` is commented out, the installer never overwrites it, and Hata never changes it again. The stub imports the classes its examples use; Pint may remove the imports while every example is commented out. A site installed before v0.4.0 runs `php artisan hata:install` again (it skips what is done) or copies the stub. A module registers in its own provider's `boot()` the same way.

**`php artisan hata:admin`** prints, per registry, what is registered (key, order, permission, the route or component), the entries that were left out with their problem, and the `modify()` and `remove()` calls that matched nothing. It is read-only, never throws (a check that fails is listed under "Left out:") and logs nothing. It lists the unmatched `modify()` and `remove()` calls and the refused `modify()` results ("Unchanged:"), but does not yet mark which entries a `modify()` or `remove()` changed (planned). Known gap: it does not report an admin menu item in an undeclared group or under a missing parent; those show up when the menu is built.

**`php artisan make:dashboard-widget Orders [--width=third|half|full]`** writes `app/Livewire/Widgets/OrdersWidget.php` and `resources/views/livewire/widgets/orders.blade.php` (never over an existing file) and prints the line to add to `HataAdminServiceProvider`. It does not edit the provider: a printed line is easier to place by hand than a generated edit is to review.

## Menu

Details, `AdminMenuItem` and the group API: `docs/specs/admin-menu.md`. Hata's own keys:

| Kind | Key | Order | Notes |
|---|---|---|---|
| Group | `platform` | 10 | |
| Group | `content` | 100 | collapsible |
| Group | `administration` | 200 | collapsible |
| Group | `account` | 300 | |
| Item | `dashboard` | 100 (default) | group `platform` |
| Item | `content.<type>` | 100, 101, … by registry order | group `content`, lazy; taxonomies are nested under their type |
| Item | `media` | 900 | group `content` |
| Item | `users` / `roles` / `settings` / `maintenance` | 10 / 20 / 30 / 40 | group `administration` |
| Item | `profile` | 10 | group `account` |

**Collapsible groups.** A group registered with `collapsible: true` folds in the sidebar. The open and closed state is kept per browser in `localStorage` under `hata.menu.collapsed` (a list of group keys) and is applied by an inline script before the first paint, so there is no flash. The group that holds the current page is always open. Cost: none on the server.

## Top bar

Desktop only (`lg` and up): a bar across the top of the content area, the height of the sidebar header. On a phone it is not shown (a second header row would cost space), and "View site" stays at the bottom of the sidebar there. That mobile link is fixed markup, not a registry entry, so `Admin::topbar()->remove('view-site')` affects the desktop bar only. A bar with no visible item is not drawn at all. Its items are plain links (no `wire:navigate`), so a link may leave the admin.

`TopbarItem` entries, two kinds:

- `TopbarItem::link($key, $label, $icon, route: '', routeParameters: [], url: null, newTab: false, permission: null, order: 100, side: 'end')`: a ghost button with the icon and the label (`$label` is a translation key or a plain string). It points to a route (built with `route()`, never by hand) or to a plain `url`.
- `TopbarItem::component($key, $component, permission: null, order: 100, side: 'end')`: a Blade component name rendered with no attributes but a `wire:key` (`'shop::topbar-cart-status'`). Something that needs live data is a Livewire component placed inside that Blade component, at its own cost.

`side` is `start` or `end`; items are ordered within each side. A link with neither a route nor a url, an undefined route and an unknown side are registration mistakes. No query.

| Key | Order | Side | What |
|---|---|---|---|
| `view-site` | 100 | end | the public site (route `home`), opens in a new tab |

## Dashboard widgets

`/admin` (`admin.dashboard`) renders the widgets the user may see in a 6-column grid. A `DashboardWidget(key, component, permission, width = 'third', order = 100)` cell takes 2, 3 or 6 columns for `third`, `half` and `full` from `md` up; below `md` every widget is full width. With no widget visible the page shows an empty state. A widget's `component` is a Livewire component name (`'widgets.orders'`, as the provider stub shows) or a class (`OrdersWidget::class`, as `make:dashboard-widget` prints). The widget's `permission` is checked when the dashboard lists it; the component must still authorize anything sensitive it loads, because a Livewire request can name any component.

Widgets load lazily in one bundled request (Livewire 4 `lazy.bundle`): the page appears at once and N widgets cost one extra request, not N. A widget is a Livewire component extending `Hata\Livewire\Admin\Widget`, which gives the card frame, a placeholder of the card's shape and the error card.

| Key | Order | Width | Title | What |
|---|---|---|---|---|
| `content` | 10 | full | Content | a tile per active type the user may list (icon, total, published and drafts), linking to the type's list. One grouped query on `entries`. "Published" counts the stored status, so a scheduled record counts as published. |
| `recent-changes` | 20 | full | Recent changes | the 8 records last edited among the types the user may list (type icon, title, type and language, status badge, time), linking to their edit pages. One query on `entries`; there is no author column, which keeps it to that one query. |

Both are shown to a user who may list at least one active content type. Their Livewire names, `hata::admin.widgets.content` and `…recent-changes`, are resolved through `Livewire::resolveMissingComponent` (Livewire's own registration ignores namespaced names).

### Writing a widget

```php
namespace App\Livewire\Widgets;

use App\Models\Order;
use Hata\Livewire\Admin\Widget;
use Illuminate\Contracts\View\View;

class OrdersWidget extends Widget
{
    public function title(): string
    {
        return __('Orders');
    }

    protected function body(): View
    {
        return view('livewire.widgets.orders', [
            'open' => Order::query()->where('status', 'open')->count(),
        ]);
    }
}
```

```blade
{{-- resources/views/livewire/widgets/orders.blade.php --}}
<div>
    <flux:heading size="xl">{{ $open }}</flux:heading>
    <flux:text>open orders</flux:text>
</div>
```

```php
// HataAdminServiceProvider::boot()
Admin::dashboard()->add(new DashboardWidget('orders', \App\Livewire\Widgets\OrdersWidget::class, 'orders.view', 'half', 30));
```

Rules:

- `icon()` (optional, a Flux icon name, default none) is shown next to the title in a tinted square; keep it a constant, the placeholder calls it too.
- Keep `title()` cheap (it runs in the dashboard's own request, for the placeholder too; a throwing `title()` is logged and shown empty outside `local` and `testing`) and `mount()` empty: do the work in `body()`. One bundled lazy request carries every widget, so an exception in `mount()` fails them all.
- All data work belongs in `body()`; it runs after the dashboard has rendered. If it throws, the card shows "This widget could not be loaded" and the exception is logged; in `local` and `testing` the exception surfaces. A mistake in the Blade markup of the returned view is not turned into that card: Livewire renders the view inside the card, where it surfaces as an ordinary error.
- A widget's cost is its own: state it (queries per render) and keep it constant, never per row.
- Use Flux and the Tailwind classes Hata's build already contains, or your own assets through `Admin::assets()`; a site never runs Hata's build.

## Settings sections

The registry and the section contract are in `docs/specs/site-settings.md` and `docs/specs/package.md` ("Adding a settings section"). `SettingsSections` is on the shared base, so `modify()`, `remove()`, `has()` and `find()` work on it (for example `Admin::settings()->modify('mail', fn ($section) => $section->with(order: 5))`). Keys are hyphens-only.

| Key | Order |
|---|---|
| `site` | 10 |
| `languages` | 20 |
| `content` | 30 |
| `access` | 40 |
| `mail` | 50 |
| `appearance` | 60 |
| `media` | 70 |

## Tests

`tests/Feature/Admin/` holds the base (`Registry/`), the menu, the top bar, the dashboard and Hata's widgets; the installer's provider step is in `tests/Feature/Install/`. A module tests its own registrations the same way: a mistake in a registration throws in `testing`.

## List columns (release 2)

`Admin::columns('users')` (or `content.*`, `content.posts`, …; `TableColumns`, a `TargetedRegistry`) adds, hides (`remove()`) or changes (`modify()`) the columns of a list between the fixed first column and the "⋮" column. A `TableColumn` has a `key` (also the `?sortBy=` value; underscores allowed), a `label` (translation key or plain text), a `value` (a `Closure(Model, ListContext)` returning a string or `Htmlable`, or a Blade view given `record` and `context`), `with` (an array for `->with()` or a `Closure(Builder)`, applied once per page), `sort` (`Closure(Builder, direction)`; makes the column sortable), `visibleIn`, `permission`, `order`, `hideBelow` (`md`/`lg`) and `align`. A column the user may not see is neither shown, loaded nor sortable; an unknown or hidden sort key falls back to the default. The users list registers its own `email` (10), `roles` (20), `locale` (30) and `created_at` (40) the same way. Lists use the `HasListExtensions` trait (`listColumns`, `applyColumnLoads()`, `registeredSort()`); tests assert a list costs the same queries with 1 and 20 rows through `Hata\Testing\AssertsListQueries::assertListQueriesStable()`.

The content list registers its own on `content.*` the same way: `taxonomies` (10, the type has active taxonomies; its header names them), `records` (20, taxonomies), `status` (30, not in the trash and the type publishes), `author` (40, the type has authors), `date` (50, sortable, not in the trash) and `removal` (60, trash only). Hiding one for a type is `Admin::columns('content.posts')->remove('author')`. A registered sort on the content list is flat: the tree order, and its indent, return with "reset sort" (or the third click on the column's header). Its eager loads are added to the page's query once; the `terms` of a type with taxonomies stay in `EntryListQuery`.

## List filters (release 2)

`Admin::filters('users')` (or `content.*`, `content.posts`, …; `TableFilters`, a `TargetedRegistry`) adds, hides or changes the filters of a list. A `TableFilter` has a `key` (lowercase letters and digits joined by hyphens or underscores, no dots: it is the `?filter[key]=` value and a Livewire model path), a `label` (translation key or plain text; the select's accessible name), `options` (`Closure(ListContext): array<string, string>`, value => label, called once per render), `apply` (`Closure(Builder, string)`), `visibleIn`, `permission` and `order`. It is drawn as a native `flux:select` with an "All" option after the list's own filters; a change resets the page and the selection. `apply` runs only for a value that is a key of the filter's options, so a forged URL value is ignored, and a filter the user may not see is neither drawn nor applied. "Clear filters" and `isFiltered()` count registered values (the users list gained a "Clear filters" button in its no-results state). The page's `filters` property and the `listFilters`, `listFilterOptions`, `applyFilters()` methods come from `HasListExtensions`.

## Row actions (release 2)

`Admin::rowActions('users')` (or `content.*`, `content.posts`, …; `RowActions`, a `TargetedRegistry`) adds items to the "⋮" menu of a list, after Hata's own and behind a separator (Hata's own items stay as they are; a site adds, never replaces them). A `RowAction` is made with `RowAction::link($key, $label, $icon, Closure $url, …)` (the URL is worked out per row from the record; `newTab: true` opens it in a new tab) or `RowAction::handler($key, $label, $icon, HandlerClass::class, …, danger: false, confirm: null)`. A handler is resolved from the container and called as `__invoke(Model $record, AdminUser $user): ?string`; a returned string is shown as a success toast (escaped), null shows none. `confirm` (a translation key or text) opens the list's one shared dialog first (`confirmRowAction`, then `runPendingRowAction`); `danger` draws the item red. Keys hold lowercase letters, digits, hyphens and underscores (no dots: they travel in `wire:click` calls). `permission` is checked per record: a string ability goes through `Gate::forUser($user)->allows($ability, $record)`, a `Closure(AdminUser, Model): bool` is called once per row and must not query (use the data the list's columns eager-load). `visibleIn` is a `Closure(ListContext): bool`; without it the action is not shown in the trash, and with it a handler in the trash loads its record with `onlyTrashed()` through the list's own query. A row whose only items are a site's still gets its menu.

`runRowAction($key, $id)` is the one way a handler runs, and it is a security boundary: the action must be visible in the list's context for the user (else 404), the record is loaded through `recordQuery()->whereKey($id)->firstOrFail()` (the list's type, the language filter and the tab, the trash being `onlyTrashed()`; on the users list every user), so an id outside the current view is a 404, and a record the action's `allows()` refuses is a 403, all before the handler is resolved. A link action cannot be run. The lists use `HasListExtensions` (`listRowActions`, `pendingRowAction`, `recordQuery()`, `forgetList()`) and the partials `partials/admin/lists/row-actions` and `action-confirm` (drawn only when a row action is registered, so a list without one has the same markup as before).

## Bulk actions (release 2)

`Admin::bulkActions('users')` (or `content.*`, `content.posts`, …; `BulkActions`, a `TargetedRegistry`) adds buttons to a list's bulk bar, after Hata's own. A `BulkAction` is `new BulkAction($key, $label, $icon, HandlerClass::class, permission: null, order: 100, visibleIn: null, danger: false, confirm: null)`. The handler is resolved from the container and called once as `__invoke(Collection $records, AdminUser $actor): ?string` with the selected records; a returned string is a success toast (escaped). `permission` (a string ability or `Closure(AdminUser): bool`) is checked for the user when the actions are listed, since there is no single record; `visibleIn` is a `Closure(ListContext): bool`, and without it the action is not shown in the trash. An action with `confirm` or `danger` always asks first in the shared dialog (`confirmBulkAction`, then `runPendingBulkAction`; a danger action without text gets a generic question). Keys follow the row actions' rule.

`runBulkAction($key)` is a security boundary like `runRowAction`: the action must be visible for the user (404) and the records are `recordQuery()->whereKey(selected ids)->get()`, so an id outside the view (another type, another tab) is dropped; an empty result calls no handler. The selection (`$selected`, now in `HasListExtensions`) is page-bound and cleared on page, search, filter, sort and tab changes and after a run. The users list gains row checkboxes, a select-page box and the bulk bar only when a bulk action is visible to the user; without one its HTML is as before. Partials: `partials/admin/lists/bulk-actions` (the buttons) and the shared `action-confirm` dialog.

## Form cards (release 2)

`Admin::formCards('content.posts')` (or `content.*`, `content.@records`, `content.@taxonomies`; `users`, `profile` and `register` are valid targets of every targeted registry and are wired to their forms in the next tasks; `FormCards`, a `TargetedRegistry`) adds a card to a form, after Hata's own blocks (they stay as they are). A `FormCard` is `new FormCard($key, ExtensionClass::class, permission: null, order: 100, area: 'main')`: `main` is drawn below the entry form's SEO card, `side` at the end of its sidebar, both in the form's own card frame (`partials/admin/forms/cards`). Keys hold lowercase letters, digits, hyphens and underscores (no dots: they are segments of `extensions.<key>.<field>`); the extension must be a class extending `FormExtension` and the area `main` or `side`, else it is a registration mistake. `permission` is null for anyone (a guest on registration), an ability checked through the Gate for the actor (never passed for a guest), or a `Closure(?AdminUser): bool` (`FormCard::allows()`).

The extension (`Hata\Support\Admin\Forms\FormExtension`, resolved from the container on every call) has `fill(?Model $record, FormContext $context): array` (the initial values, once on mount; on create `$record` is null; one query at most), `rules(FormContext $context): array` (rules by field, relative to the card), `view(): string` and `save(Model $record, array $values, FormContext $context): void`. `FormContext` carries `target`, `type` (the `ContentType`, or null), `actor` (null for a guest) and `isCreate()`. The view gets `$key`, `$values` (its own), `$field` (`Closure(string): ComponentAttributeBag`: `wire:model="extensions.<key>.<field>"` in a Livewire form, `name="extensions[<key>][<field>]"` with the old input in a plain one) and `$errorName` (`Closure(string): string`, the dot path for `<flux:error>`). Bind an input with `<flux:input :attributes="$field('birthday')" />` (Blade spreads only `{{ $attributes }}` inside a component tag, so `{{ $field(...) }}` does not work there); interactivity is Alpine only. A read-only form disables the card's inputs (the card is drawn in a disabled `fieldset`).

`FormExtensions` (stateless) does the work for any form: `fill()`, `rules()` (keyed `extensions.<key>.<field>`), `attributes()` (each field's own name in messages), `save()` and `field()`. A card's values are untrusted: only the fields its `rules()` name reach `save()` (a named field that was not posted is null), and a card the actor may not see is neither drawn, validated nor saved, whatever is posted. Livewire pages use the trait `Hata\Livewire\Admin\Concerns\HasFormExtensions` (`$extensions`, the computed `formCards`, `formContext()`, `mountExtensions()`, `extensionErrors()`, `saveExtensions()`).

The entry form (create and edit): the cards' values are `$extensions`, outside `$form`; with no card it is `[]` and the form's markup is unchanged. Save first validates the cards (no throw); if any fails, the form's own validation also runs without writing (`EntryInput::validate`), both sets of errors show together and nothing is stored. Otherwise `CreateEntry`/`UpdateEntry::handle(..., alsoSave:)` call each card's `save()` with the saved record inside their transaction, after the record is written and before the create page redirects (the edit page stays, decision 65); an exception in `save()` rolls the record back. The actions run `alsoSave` through `FormCardFailed::guard()` (and `FormExtensions::save()` wraps each card, naming it), so their own catches (a unique violation shown as "slug taken", a language taken) never take a card's failure for the record's: the page shows a card's `ValidationException` under the card's fields (keys relative to the card, or full `extensions.` paths) plus a danger toast, and rethrows anything else as it was (`FormCardFailed::original()`). Validation belongs in `rules()`. Every field the rules name (by top-level key: `tags.*` names `tags`) is in the card's values from mount, null when `fill()` leaves it out. The unsaved-changes guard's snapshot includes `$wire.extensions`.

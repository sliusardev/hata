# Admin extension points

> The admin address is `config('hata.admin.path')`; `/admin` stands for it. Design: `docs/superpowers/specs/2026-11-25-admin-extension-points-design.md`; the reasons are decision 81 in `CLAUDE.md`.

A site or a module adds, reorders, changes and hides parts of the admin **without editing or overriding a Hata file**, so `composer update` keeps bringing fixes. Hata's own parts are registered through the same API, so a site can reorder or hide any of them by key.

Release 1 (v0.4.0) covers four registries: the sidebar menu, the desktop top bar, the dashboard widgets and the site settings sections. Release 2 (v0.5.0) adds five **targeted** registries (list columns, list filters, row actions, bulk actions and form cards, see "Targets") and the content type's `without()`. Admin assets (`AdminAssets`) stay as they were.

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

`Hata\Admin` is a plain class with static methods that resolve the singletons (not a facade, so PHPStan and the IDE see the real types): `Admin::menu()`, `Admin::topbar()`, `Admin::dashboard()`, `Admin::settings()`, `Admin::assets()` and, aimed at a target, `Admin::columns($targets)`, `Admin::filters($targets)`, `Admin::rowActions($targets)`, `Admin::bulkActions($targets)` and `Admin::formCards($targets)`. `app(AdminMenu::class)` and the other old spellings keep working.

`php artisan hata:install` writes `app/Providers/HataAdminServiceProvider.php` from `stubs/hata-admin-service-provider.stub` and lists it in `bootstrap/providers.php`. It is the site's file: every example in its `boot()` is commented out, the installer never overwrites it, and Hata never changes it again. The stub imports the classes its examples use; Pint may remove the imports while every example is commented out. A site installed before v0.4.0 runs `php artisan hata:install` again (it skips what is done) or copies the stub. A module registers in its own provider's `boot()` the same way.

**`php artisan hata:admin`** prints, per registry, what is registered (key, order, permission, the route or component), the entries that were left out with their problem, and the `modify()` and `remove()` calls that matched nothing ("Unmatched:"). For each targeted registry it prints one table per target that received a call (`List columns [users]`, `List columns [content.posts]`, `Form cards [profile]`, …), listing what that list or form resolves, Hata's own entries included, so a site sees what a narrower target inherited from `content.*`. It also lists the refused `modify()` results ("Unchanged:"). It is read-only, never throws (a check that fails is listed under "Left out:") and logs nothing. It does not yet mark which entries a `modify()` or `remove()` changed (planned). Known gap: it does not report an admin menu item in an undeclared group or under a missing parent; those show up when the menu is built. A target nobody called is not listed, so Hata's own `content.*` and `users` columns are what a fresh site sees.

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

`tests/Feature/Admin/` holds the base and the targeted base (`Registry/`), the menu, the top bar, the dashboard and Hata's widgets, `Lists/` (columns, filters, row actions, bulk actions) and `Forms/` (entry and user form cards); the profile and registration cards are in `tests/Feature/Settings/ProfileCardsTest.php` and `tests/Feature/Auth/RegistrationCardsTest.php`, the type's parts in `tests/Feature/Content/ContentTypePartsTest.php`; the installer's provider step, whose test checks that every class the stub imports exists and that the stub with its examples uncommented is valid PHP, is in `tests/Feature/Install/`. A module tests its own registrations the same way: a mistake in a registration throws in `testing`, and a list's cost is asserted with `AssertsListQueries` (below).

## Targets (release 2)

A **targeted** registry (`Hata\Support\Admin\Registry\TargetedRegistry`) holds entries aimed at a list or a form instead of one admin-wide list. `Admin::columns('content.posts')` returns a `TargetScope` whose `add()`, `remove()` and `modify()` are recorded for that target (the scope returns itself, so calls chain); an array of targets records each call for every one of them.

| Target | Aims at |
|---|---|
| `users` | the users list; for form cards also the user form (create and edit) |
| `profile` | the signed-in user's profile page (form cards only in practice) |
| `register` | the registration form (form cards only in practice) |
| `content.*` | every content list and entry form |
| `content.@records` | the lists and forms of ordinary types |
| `content.@taxonomies` | the lists and forms of taxonomies |
| `content.<type key>` | one type (`content.posts`), key `[a-z0-9_-]+` |

Any other target is a registration mistake (`LogicException` in `local` and `testing`, one logged warning per process elsewhere, and the call is skipped).

**Resolving.** Nothing is decided when a call is made: the calls are recorded in order, and a list or form asks `resolve($target)`, which replays the chain of the target (`content.posts` is `content.*`, then `content.@records` or `content.@taxonomies`, then `content.posts`) into a fresh registry: all `add()` calls first, then the `modify()` calls, then the `remove()` calls, each from the widest target to the narrowest, within a target in call order. So a narrower target overrides a wider one's entry of the same key, and removes or changes it for itself only, whatever order the providers booted in; `Admin::columns('content.posts')->remove('author')` hides the column on the posts list and leaves the pages list alone. The result is memoized until the next call, and `chain()` reads the content types, so it must run when a list opens, never at boot. `modify()` and `remove()` of a key that is not there are skipped and shown by `hata:admin`, as in release 1.

**Keys.** Each registry has its own alphabet (`…Registry::keyPattern()`), because the keys are used differently:

| Registry | Key alphabet | Why |
|---|---|---|
| columns | lowercase letters and digits joined by hyphens, underscores or dots | the key is the `?sortBy=` value (`created_at`) |
| filters | joined by hyphens or underscores, no dots | the key is a Livewire model path (`filters.<key>`) and a `?filter[key]=` value |
| row actions, bulk actions | joined by hyphens or underscores, no dots | keys travel in `wire:click` calls |
| form cards | joined by hyphens or underscores, no dots | the key is a segment of `extensions.<key>.<field>` |

**Labels.** A `label` (and a filter's option labels, a `confirm` text) is a translation key or plain text and passes through `__()`, which returns an unknown key unchanged: a site writes `'Views'`, a module `'shop::lists.views'`.

## Lists (release 2)

The users list and every content list (`hata::admin.users.index`, `hata::admin.content.index`) take the four list registries through the trait `Hata\Livewire\Admin\Concerns\HasListExtensions`. A page names its target in `listContext()` (a `ListContext`: `target`, `type` or null for users, the open `tab`; `isTrash()`, `isTaxonomy()`) and supplies `recordQuery()` and `forgetList()`. The first column of each list (the name or title with its link, the tree indent, the language chips and badges) and the "⋮" column are fixed and cannot be registered or removed.

### Columns

`Admin::columns('users')`, `content.*`, `content.posts`, … (`TableColumns`). A `TableColumn(key, label, value, with = [], sort = null, visibleIn = null, permission = null, order = 100, hideBelow = null, align = 'start')`:

| Argument | Meaning |
|---|---|
| `value` | a `Closure(Model, ListContext): string\|Htmlable\|null` (a string is escaped) or the name of a Blade view rendered with `record` and `context` |
| `with` | an array for `->with()` or a `Closure(Builder)` (`withCount`, `addSelect`), applied once to the page's query, never per row |
| `sort` | a `Closure(Builder, 'asc'\|'desc')`; makes the column sortable by its key |
| `visibleIn` | a `Closure(ListContext): bool`; null is every tab |
| `permission`, `order` | as in the shared base; a column the user may not see is neither drawn nor loaded nor sortable |
| `hideBelow`, `align` | `md` or `lg`; `start`, `center` or `end` |

A site column's `with` for a relation the list already loads (the content list loads its `terms`, the users list its `roles`) replaces the list's own constrained load, so name another relation or add a `Closure`. An unknown or hidden `?sortBy=` falls back to the list's default. A registered sort on the content list is flat: the tree order and its indent come back with "Tree order" (or the third click on the header). Registered sorts are never on JSON `fields` keys (decision 57).

Hata's own columns are registered the same way (`HataServiceProvider::registerListColumns()`), so a site can reorder or hide them:

| Target | Key | Order | Shown |
|---|---|---|---|
| `users` | `email` / `roles` / `locale` / `created_at` | 10 / 20 / 30 / 40 | always; `email` and `created_at` are sortable |
| `content.*` | `taxonomies` | 10 | the type has an active taxonomy |
| `content.*` | `records` | 20 | a taxonomy: how many records use the term |
| `content.*` | `status` | 30 | not in the trash and the type publishes |
| `content.*` | `author` | 40 | the type has authors |
| `content.*` | `date` | 50 | not in the trash; sortable |
| `content.*` | `removal` | 60 | the trash tab |

### Filters

`Admin::filters(…)` (`TableFilters`). A `TableFilter(key, label, options, apply, visibleIn = null, permission = null, order = 100)`: `options` is a `Closure(ListContext): array<string, string>` (value => label), called once per render; `apply` is a `Closure(Builder, string)`. A filter is drawn as a native `flux:select` with an "All" option, after the list's own filters; a change resets the page and the selection. `apply` runs only for a value that is a key of the options, so a forged `?filter[key]=` value is ignored; a filter the user may not see is neither drawn nor applied. "Clear filters" counts registered values. Tab counts ignore registered filters, as they ignore the search. The page's `filters` property, `listFilters`, `listFilterOptions` and `applyFilters()` come from the trait.

### Row actions

`Admin::rowActions(…)` (`RowActions`) adds items to the "⋮" menu after Hata's own and behind a separator (Hata's own items stay; a site adds, never replaces them). Made with `RowAction::link($key, $label, $icon, Closure $url, permission, order, visibleIn, newTab)` (the URL is worked out per row; one query at most is the caller's business, use eager loads) or `RowAction::handler($key, $label, $icon, HandlerClass::class, permission, order, visibleIn, danger, confirm)`. A handler is resolved from the container and called as `__invoke(Model $record, AdminUser $user): ?string`; a returned string is shown as a success toast (escaped), null shows none. `confirm` opens the list's one shared dialog first; `danger` draws the item red.

`permission` is checked **per record**: a string ability goes through `Gate::forUser($user)->allows($ability, $record)`, a `Closure(AdminUser, Model): bool` is called once per row and must not query (use what the columns eager-load). `runRowAction($key, $id)` is the security boundary: the action must be visible in the list's context for the user (else 404), the record is loaded through `recordQuery()->whereKey($id)->firstOrFail()` (the list's scope: type, language filter and tab, the trash being `onlyTrashed()`; on the users list every user), so an id outside the list's view is a 404, and a record the action refuses is a 403, all before the handler is resolved. A link action cannot be run. **Default `visibleIn`: not in the trash**; give `visibleIn` to show an action there, and its handler then loads from the list's trash query.

### Bulk actions

`Admin::bulkActions(…)` (`BulkActions`) adds buttons to the bulk bar after Hata's own: `new BulkAction($key, $label, $icon, HandlerClass::class, permission, order, visibleIn, danger, confirm)`. The handler is called once as `__invoke(Collection $records, AdminUser $actor): ?string`. Differences from row actions: `permission` (a string ability or `Closure(AdminUser): bool`) is checked **once for the actor** (there is no single record); the records come only through `recordQuery()->whereKey(selected ids)`, so an id outside the view is dropped and an empty result calls no handler; the selection (`$selected`) is page-bound and cleared on page, search, filter, sort and tab changes and after a run; an action with `confirm` or `danger` always asks first (a danger action without text gets a generic question). The confirm step is a UI step, not authorization. Same default `visibleIn` (not in the trash).

**On the users list a handler receives whoever was selected, including super admins and the actor.** The handler must apply the protection rules itself with `Hata\Support\Permissions\AccessRules` (decision 21); the registry checks only the action's permission.

The users list gains row checkboxes, a select-page box and the bulk bar only when a bulk action is visible to the user; without one, and without a row action, its HTML is as before. Partials: `partials/admin/lists/row-actions`, `bulk-actions` and the shared `action-confirm` dialog. Livewire parts: `confirmRowAction`/`runPendingRowAction`/`runRowAction`, `confirmBulkAction`/`runPendingBulkAction`/`runBulkAction`.

### Cost

A column, filter, row action or bulk action costs **at most one query per page, never one per row**: columns load through `with` once, filter options are called once per render, a row action's `permission` must not query, a bulk handler is called once. Hata's own lists are asserted with `Hata\Testing\AssertsListQueries` (`assertListQueriesStable($seed, $render)`: seeds one record, renders once to warm the caches, counts the queries, seeds up to twenty records, counts again, and fails when the two differ). A site or module uses the same trait in its tests.

```php
uses(AssertsListQueries::class);

test('the orders column costs one query per page', function () {
    $this->actingAs(User::factory()->withRole('admin')->create());

    $this->assertListQueriesStable(
        seed: fn (int $count) => Order::factory()->count($count)->create(),
        render: fn () => Livewire::test('hata::admin.users.index'),
    );
});
```

## Form cards (release 2)

`Admin::formCards(…)` (`FormCards`) adds a card to a form after Hata's own blocks (they stay as they are). A `FormCard($key, ExtensionClass::class, permission = null, order = 100, area = 'main')`: `main` is drawn below the entry form's SEO card, `side` at the end of its sidebar; the user form is one column (`main` cards, then `side`); on `profile` and `register` the cards are drawn after the email field. The entry form and the user form draw a card in Hata's card frame (`partials/admin/forms/cards`); **on `profile` and `register` the cards are drawn bare**, because the fields of those forms are bare, so one card view serves `users`, `profile` and `register`. The extension must be a class extending `FormExtension` and the area `main` or `side`, else it is a registration mistake. `permission` is null for anyone (a guest on registration), an ability checked through the Gate for the actor (never for a guest), or a `Closure(?AdminUser): bool`.

### `FormExtension`

The class (`Hata\Support\Admin\Forms\FormExtension`) is resolved from the container on every call and keeps no state:

| Method | Meaning |
|---|---|
| `fill(?Model $record, FormContext $context): array` | the initial values, once when the form opens (on create `$record` is null); one query at most |
| `rules(FormContext $context): array` | rules by field, relative to the card |
| `view(): string` | the Blade view drawn inside the frame |
| `save(Model $record, array $values, FormContext $context): void` | stores the validated values inside the form's transaction |

`FormContext` has `target`, `type` (the `ContentType`, null outside the entry form), `actor` (null for a guest on registration) and `isCreate()`.

**The view** receives `$key`, `$values` (the card's own), `$field` (a `Closure(string): ComponentAttributeBag`) and `$errorName` (a `Closure(string): string`). Bind an input with `<flux:input :attributes="$field('birthday')" />`; `{{ $field('birthday') }}` does not work, because Blade spreads only `{{ $attributes }}` inside a component tag. The error element reads `<flux:error :name="$errorName('birthday')" />`. `$field` gives `wire:model="extensions.<key>.<field>"` in a Livewire form and `name="extensions[<key>][<field>]"` plus the old input on registration. Interactivity is Alpine only; a read-only form disables the card's inputs.

**Validation and saving.**

- A card's values are untrusted. **Only the top-level keys named by `rules()` reach `save()`** (`tags.*` lets the whole `tags` value through, checked by its rules); a named field that was not posted is null, and every named field is in the card's values from mount. A card the actor may not see is neither drawn, validated nor saved, whatever is posted.
- **Validation belongs in `rules()`**: the card's errors and the form's own are shown together and any error stops the whole save, so nothing is written. Do not validate in `save()`.
- `save()` runs inside the form's transaction, after the record is written and before any redirect (the edit page stays, decision 65). **Whatever `save()` throws rolls the record back.** Hata wraps it in `Hata\Exceptions\FormCardFailed` (`FormCardFailed::guard()`), so an action's own catches (a taken slug, a taken language) never take a card's failure for the record's. The page then shows a `ValidationException` (keys relative to the card, or full `extensions.` paths) under the card's fields plus a danger toast, and rethrows anything else as the original exception (`FormCardFailed::original()`).
- On the entry form, **when `CreateEntry` finds that the group already has a record in that language, nothing is created and the cards are not saved.**

`FormExtensions` (stateless) does the work for any form (`fill()`, `rules()` keyed `extensions.<key>.<field>`, `attributes()`, `save()`, `field()`); Livewire pages use the trait `Hata\Livewire\Admin\Concerns\HasFormExtensions` (`$extensions`, the computed `formCards`, `formContext()`, `mountExtensions()`, `validateWithCards()`, `showCardFailure()`, `saveExtensions()`). The unsaved-changes guard of the entry form includes `$wire.extensions`.

**Where each form saves.** The entry form: `CreateEntry`/`UpdateEntry::handle(..., alsoSave:)`, after the record is written. The user form: `CreateUser`/`UpdateUser::handle(..., alsoSave:)`, after the roles are synced, so a `ProtectedActionException` (a role the actor may not assign) rolls everything back too. The profile page (`/settings/profile`, the signed-in user editing themselves): the user and the cards in one `DB::transaction`. Registration (Fortify's plain Blade form): `CreateNewUser` validates Hata's rules and the cards' in one `Validator`, creates the user, the default role and the cards in one transaction, and a card's `ValidationException` comes back under its fields with the old input; the context is `FormContext('register', null, null, true)`, a string ability is never satisfied by a guest and a `Closure` receives null. A form with no card has the same markup and behaviour as before.

### Example: a birthday and gender on the user, profile and registration forms

Store a site's own fields in columns of `users` (a migration of the site: `date('birthday')->nullable()`, `string('gender')->nullable()`), or, for a module, in a table of its own keyed by `user_id` (never in Hata's tables, and not in the JSON `fields` of `entries` for anything a list filters or sorts by, decision 57).

```php
use Hata\Support\Admin\Forms\FormContext;
use Hata\Support\Admin\Forms\FormExtension;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\Rule;

class PersonalCard extends FormExtension
{
    public function fill(?Model $record, FormContext $context): array
    {
        return [
            'birthday' => $record?->birthday?->format('Y-m-d'),
            'gender' => $record?->gender,
        ];
    }

    public function rules(FormContext $context): array
    {
        return [
            'birthday' => ['nullable', 'date', 'before:today'],
            'gender' => ['nullable', Rule::in(['female', 'male', 'other'])],
        ];
    }

    public function view(): string
    {
        return 'admin.cards.personal';
    }

    public function save(Model $record, array $values, FormContext $context): void
    {
        $record->forceFill($values)->save();
    }
}
```

```blade
{{-- resources/views/admin/cards/personal.blade.php --}}
<div class="grid gap-4 sm:grid-cols-2">
    <flux:field>
        <flux:label>{{ __('Birthday') }}</flux:label>
        <flux:input type="date" :attributes="$field('birthday')" />
        <flux:error :name="$errorName('birthday')" />
    </flux:field>
    <flux:field>
        <flux:label>{{ __('Gender') }}</flux:label>
        <flux:select :attributes="$field('gender')">
            <flux:select.option value="">-</flux:select.option>
            <flux:select.option value="female">{{ __('Female') }}</flux:select.option>
            <flux:select.option value="male">{{ __('Male') }}</flux:select.option>
            <flux:select.option value="other">{{ __('Other') }}</flux:select.option>
        </flux:select>
        <flux:error :name="$errorName('gender')" />
    </flux:field>
</div>
```

```php
// HataAdminServiceProvider::boot()
Admin::formCards(['users', 'profile', 'register'])->add(new FormCard('personal', PersonalCard::class, order: 50));
```

## Content type parts: `without()`

A `ContentType` subclass overrides the protected `without()` to switch parts of the common form off: `body`, `image`, `publication` (status and date), `author`, `excerpt` and `seo`. `supports($feature)` answers whether a record has a part: it is false for a part in `without()`, and for a taxonomy everything but `excerpt` and `seo` (a term is a name, an address and a description, always published). A part that is off leaves the form, has no validation rule and is never written: **a switched-off part keeps its stored value**, so switching it on again restores it. A type that overrides `supports()` itself should move to `without()`. The details (the public page, the list columns that follow) are in `docs/specs/content.md`.

## Hata's own entries in the targeted registries

Hata registers columns only (the tables above); the row action, bulk action, filter and form card registries start empty, so a site's first entry is the only one, and Hata's own list actions and form blocks are not entries and cannot be replaced or removed through them.

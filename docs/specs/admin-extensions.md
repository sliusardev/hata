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
| `entriesFor($user)` | `all()` limited to what the user may see (each registry wraps it as `for($user)`). |

```php
Admin::menu()->modify('media', fn (AdminMenuItem $item) => $item->with(order: 5));
Admin::dashboard()->remove('recent-changes');
```

**Permission.** `null` means everyone who reaches the page; a string is a gate ability (`Gate::forUser($user)`); a `Closure(AdminUser): bool` decides itself. Hata's admin menu items also need `admin.access`; a module's should too.

**Order.** An integer; lower comes first, ties keep insertion order. Hata's own entries use steps of 10 (the tables below), so a site slots between them with `15`.

**Keys.** Lowercase letters and digits joined by hyphens or dots (`^[a-z0-9]+([.-][a-z0-9]+)*$`); a module prefixes its keys (`shop-orders`). Two registries widen this: admin menu items also allow underscores and hyphens anywhere in a segment (`[a-z0-9_-]+` joined by dots), because content type keys have that alphabet (`content.<type>`); settings section keys stay hyphens-only, because they are `?tab=` values.

**`modify()` and `remove()` of a key that is not there are skipped, never a mistake.** Some entries exist only at read time and depend on data (a content type switched off in the settings has no menu item; a lazy provider may add nothing). `php artisan hata:admin` lists every call that matched nothing, so a typo is still easy to find.

**Registration mistakes** (a bad key, an undefined route, a component Livewire cannot resolve, an unknown width or side, an item in an undeclared menu group) throw a `LogicException` naming the registry and the key in `local` and `testing`, so a module's own tests catch them. Elsewhere the entry is left out and one warning per message per process is logged. The menu checks the routes of every item, not only of those the user sees, so a mistake shows to the developer whoever is signed in.

**No work at boot.** Registering stores objects; nothing (routes, components, translations) is resolved until a page reads the registry, and the registries make no queries.

**Provider order.** `HataServiceProvider::register()` registers Hata's own entries in an application `booting` callback, which runs before any provider's `boot()`. A site's or module's `boot()` therefore always meets Hata's entries, and `remove()` and `modify()` work in any call order.

## `Hata\Admin` and the site's provider

`Hata\Admin` is a plain class with static methods that resolve the singletons (not a facade, so PHPStan and the IDE see the real types): `Admin::menu()`, `Admin::topbar()`, `Admin::dashboard()`, `Admin::settings()`, `Admin::assets()`. `app(AdminMenu::class)` and the other old spellings keep working.

`php artisan hata:install` writes `app/Providers/HataAdminServiceProvider.php` from `stubs/hata-admin-service-provider.stub` and lists it in `bootstrap/providers.php`. It is the site's file: every example in its `boot()` is commented out, the installer never overwrites it, and Hata never changes it again. The stub imports the classes its examples use; Pint may remove the imports while every example is commented out. A site installed before v0.4.0 runs `php artisan hata:install` again (it skips what is done) or copies the stub. A module registers in its own provider's `boot()` the same way.

**`php artisan hata:admin`** prints, per registry, what is registered (key, order, permission, the route or component), the entries that were left out with their problem, and the `modify()` and `remove()` calls that matched nothing. It is read-only, never throws and logs nothing.

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

Desktop only (`lg` and up): a bar across the top of the content area, the height of the sidebar header. On a phone it is not shown (a second header row would cost space), and "View site" stays at the bottom of the sidebar there. Its items are plain links (no `wire:navigate`), so a link may leave the admin.

`TopbarItem` entries, two kinds:

- `TopbarItem::link($key, $label, $icon, route: '', routeParameters: [], url: null, newTab: false, permission: null, order: 100, side: 'end')`: a ghost button with the icon and the label (`$label` is a translation key or a plain string). It points to a route (built with `route()`, never by hand) or to a plain `url`.
- `TopbarItem::component($key, $component, permission: null, order: 100, side: 'end')`: a Blade component name rendered with no attributes but a `wire:key` (`'shop::topbar-cart-status'`). Something that needs live data is a Livewire component placed inside that Blade component, at its own cost.

`side` is `start` or `end`; items are ordered within each side. A link with neither a route nor a url, an undefined route and an unknown side are registration mistakes. No query.

| Key | Order | Side | What |
|---|---|---|---|
| `view-site` | 100 | end | the public site (route `home`), opens in a new tab |

## Dashboard widgets

`/admin` (`admin.dashboard`) renders the widgets the user may see in a 6-column grid. A `DashboardWidget(key, component, permission, width = 'third', order = 100)` cell takes 2, 3 or 6 columns for `third`, `half` and `full` from `md` up; below `md` every widget is full width. With no widget visible the page shows an empty state. The widget's `permission` is checked when the dashboard lists it; the component must still authorize anything sensitive it loads, because a Livewire request can name any component.

Widgets load lazily in one bundled request (Livewire 4 `lazy.bundle`): the page appears at once and N widgets cost one extra request, not N. A widget is a Livewire component extending `Hata\Livewire\Admin\Widget`, which gives the card frame, a placeholder of the card's shape and the error card.

| Key | Order | Width | Title | What |
|---|---|---|---|---|
| `content` | 10 | third | Content | per active type the user may list: published, drafts, total, linking to the type's list. One grouped query on `entries`. "Published" counts the stored status, so a scheduled record counts as published. |
| `recent-changes` | 20 | half | Recent changes | the 8 records last edited among the types the user may list, linking to their edit pages. One query on `entries`; there is no author column, which keeps it to that one query. |

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

- `title()` must stay cheap: the lazy placeholder calls it before `body()` runs.
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

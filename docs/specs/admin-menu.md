# Admin menu

The admin sidebar is built from a registry, `Hata\Support\Admin\AdminMenu` (a singleton). Hata's own sections
(dashboard, content types with their taxonomies, media, users, roles, site settings, maintenance, the account link)
register through it in `HataServiceProvider::registerAdminMenu()` and `ContentServiceProvider`; a module registers
its own in a provider's `boot()`. The sidebar (`layouts/app/sidebar.blade.php`) renders `AdminMenu::for($user)`.

## API

- `group(string $key, string $label, int $order)`: declares a group (heading). Hata's: `platform` 10, `content` 100,
  `administration` 200, `account` 300; a module picks a number between them.
- `add(AdminMenuItem $item)`: adds an item; the same key replaces the earlier one.
- `remove(string $key)`: removes an item by key (also a Hata one).
- `lazy(Closure(): iterable<AdminMenuItem>)`: items worked out on every render, for items that follow data which
  changes after boot (the content types and their on/off switch). The closure runs on every sidebar render: keep it
  cheap and make no queries. A lazy item with the same key as a fixed one overrides it.
- `for(AdminUser $user)`: the groups in order, each with the items the user may see; empty groups are dropped.

`AdminMenuItem(key, label, icon, route, permission, group, order = 100, activePattern = null, parent = null,
routeParameters = [])`:

- `label`: a translation key or a plain string (passed through `__()`).
- `icon`: a Flux icon name (outline; the solid variant is used while the item is current).
- `route`: a route name; the URL is built with `route()`, never by hand (the admin path is configurable).
- `permission`: a gate ability (`can`), a `Closure(AdminUser): bool`, or `null` for any signed-in user. Hata's admin
  items also require `admin.access`; a module's item should too (`fn ($u) => $u->can('admin.access') && ...`).
- `activePattern`: a `routeIs()` pattern, by default the route itself; several are joined with `|`
  (`profile.edit|language.edit`).
- `routeParameters`: passed to `route()`; the item is current only while the current route has the same values for
  them (one `admin.content.index` route serves every content type).
- `parent`: the key of an item in the same group it is nested under; it is listed right after its parent whatever its
  own order (taxonomies under their type).

## Order

Groups by `order`, items in a group by `order`; ties keep the order they were added in (fixed items first, then lazy
ones). Children follow their parent in their own order. A child is shown even when its parent is hidden from the user.

## Mistakes in a registration

An item in a group that was never declared, a parent that is not an item of the group, a parent cycle, or a route that
is not defined throws a `LogicException` naming the item, in the `local` and `testing` environments and when
`app.debug` is on, so a module's own tests fail. In production the item is skipped and a warning is logged. Other
errors (a missing route parameter, for one) are never swallowed.

Tests: `tests/Feature/Admin/AdminMenuTest.php` (an explicit list of today's sidebar, visibility per kind of user,
current state, and the rules above).

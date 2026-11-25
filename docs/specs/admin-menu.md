# Admin menu

The admin sidebar is built from a registry, `Hata\Support\Admin\AdminMenu` (a singleton, on the shared base of `docs/specs/admin-extensions.md`; reached as `Admin::menu()`). Hata's own sections
(dashboard, content types with their taxonomies, media, users, roles, site settings, maintenance, the account link)
register through it in `HataServiceProvider::registerAdminMenu()` and `ContentServiceProvider`; a module registers
its own in a provider's `boot()`. The sidebar (`layouts/app/sidebar.blade.php`) renders `AdminMenu::for($user)`.

## API

- `group(string $key, string $label, int $order, bool $collapsible = false)`: declares a group (a heading). A collapsible group
  can be folded in the sidebar; its state is kept per browser (see "Collapsible groups"). Hata's groups: `platform` 10,
  `content` 100 (collapsible), `administration` 200 (collapsible), `account` 300; a module picks a number between them.
- `modifyGroup(string $key, Closure $change)`: changes a declared group (`label`, `order`, `collapsible`) when the menu is built,
  whenever the call is made: `Admin::menu()->modifyGroup('content', fn (array $group) => [...$group, 'collapsible' => false])`.
  A group that is never declared is skipped.
- `add(AdminMenuItem $item)`: adds an item; the same key replaces the earlier one.
- `remove(string $key)`: hides an item by key for good (also a Hata or lazy one, such as a content type's `content.posts`),
  whatever the call order; to replace an item, `add()` one with the same key. `modify(string $key, Closure $change)` changes
  an item when the menu is read (`fn (AdminMenuItem $item) => $item->with(order: 5)`). A key that is not there is skipped
  and listed by `php artisan hata:admin` (`docs/specs/admin-extensions.md`). Hata registers its entries while the
  application boots, before any provider's `boot()`.
- `lazy(Closure(): iterable<AdminMenuItem>)`: items worked out on every render, for items that follow data which
  changes after boot (the content types and their on/off switch). The closure runs on every sidebar render: keep it
  cheap and make no queries. A lazy item with the same key as a fixed one overrides it.
- `for(AdminUser $user)`: the groups in order, each with the items the user may see; empty groups are dropped.

`AdminMenuItem(key, label, icon, route, permission, group, order = 100, activePattern = null, parent = null,
routeParameters = [])`:

- `key`: lowercase letters, digits, hyphens and underscores, in segments joined by dots (`content.blog_posts`): wider than other
  registries, because content type keys have that alphabet.
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

## The site settings item

The "Site settings" item (Administration group, `admin.settings.edit`) has no fixed permission: it is shown when the
user may open at least one section of the settings page, decided by `SettingsSections::visibleTo()` from the sections'
permissions alone (no component is resolved, no query is made). Hata's own sections need `settings.edit`; a module's
section with its own permission shows the item to users who have only that one (`docs/specs/site-settings.md`).

## Collapsible groups

A collapsible group is rendered as an expandable `flux:sidebar.group`. Which groups are folded is kept per browser in
`localStorage` under `hata.menu.collapsed` (a list of group keys) and applied by an inline script in the sidebar before
the first paint; the group that holds the current page is always open. Hata's `content` and `administration` groups are
collapsible. No server cost.

The "View site" link is in the desktop top bar (`docs/specs/admin-extensions.md`); the sidebar keeps it only below `lg`.

## Order

Groups by `order`, items in a group by `order`; ties keep the order they were added in (fixed items first, then lazy
ones). Children follow their parent in their own order. A child is shown even when its parent is hidden from the user.

## Mistakes in a registration

An item in a group that was never declared, a parent that is not an item of the group, a parent cycle, or a route that
is not defined (checked for every item, not only the ones the user sees) throws a `LogicException` naming the item, in the `local` and `testing` environments and when
`app.debug` is on, so a module's own tests fail. In production the item is skipped and a warning is logged. Other
errors (a missing route parameter, for one) are never swallowed.

Tests: `tests/Feature/Admin/AdminMenuTest.php` (an explicit list of today's sidebar, visibility per kind of user,
current state, and the rules above).

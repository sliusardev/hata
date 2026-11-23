# Roles and permissions page

> The admin address is `config('hata.admin.path')` (env `HATA_ADMIN_PATH`, default `admin`); `/admin` in this spec stands for it. Route names stay `admin.*`.

Managing roles and assigning permissions to them. Permissions are declared in code: administrators can't create or delete them, only assign them to roles.

## Pages

| Page | Route | Name | Permission |
|---|---|---|---|
| List | `/admin/roles` | `admin.roles.index` | `roles.view` |
| Create | `/admin/roles/create` | `admin.roles.create` | `roles.create` |
| Edit | `/admin/roles/{role}/edit` | `admin.roles.edit` | `roles.edit`; with only `roles.view` it opens in view mode |

Components: `resources/views/pages/admin/roles/`. The create and edit pages extend `App\Livewire\Admin\RoleFormPage` (form plus permission matrix). The navigation item "Roles" is shown only with `roles.view`.

## Permissions

- **Registry:** `config/internal/permissions.php` → `groups` (`resource => [actions]`, name `resource.action`). Modules extend it with `app(PermissionRegistry::class)->register('pages', [...])`.
- **Permissions:** `admin.access`, `users.view|create|edit|delete`, `roles.view|create|edit|delete`, `settings.edit` (see [site settings](site-settings.md)), `media.view|upload|edit|delete` (see [media](media.md)), and per content type `<type>.view|create|edit|delete`, today `pages.view|create|edit|delete` (registered by `ContentTypes::register()`, not in the config file; see [content](content.md)).
- **Sync:** `php artisan permissions:sync` creates missing permissions and lists stale ones; `--prune` deletes them. It also clears the Spatie cache.
- **Labels:** `permissions.groups.<resource>`, `permissions.items.<resource>.<action>` in the translations of the registering owner: Hata's own groups and kit types in `hata::permissions` (`resources/lang/*/permissions.php`), a site's or module's in its own `permissions` (or `shop::permissions` for a package, passed to `PermissionRegistry::register($group, $actions, $prefix)`). A group's label prefix is that of its first registration, a permission's that of the registration that added it; `groupLabel()`/`permissionLabel()` fall back to the site's `permissions.*` when the owner has no line. A test checks every registered permission has a label in every language through the key the registry builds.
- **Checks in code** always use permissions (`can('users.edit')`), never role names.

## System roles

| Role | Permissions | Note |
|---|---|---|
| `super-admin` | all, through `Gate::before` | can't be changed or deleted |
| `admin` | all registered (`*`), except the reserved ones | |
| `editor` | `admin.access`, the four `media.*` and the four `pages.*` permissions | |
| `author` | `admin.access` | |

**Reserved permissions** (`config/internal/permissions.php` → `reserved`, currently `maintenance.run`): `*` in a role's defaults does not grant them (`RoleSeeder` subtracts the list when it expands `*`). They are listed in the registry, the matrix and `permissions:sync` like any permission, so a super admin can give one to a role on purpose; rule 5 keeps everyone else from granting what they don't have.

- Created by `RoleSeeder` (from `HataSeeder`) with `is_system = true`. Reseeding only adds permissions, never removes them.
- Default permissions are in `config/internal/permissions.php` → `roles`. Names are translated via `roles.names.<name>`.
- The `App\Models\Role` model (extends Spatie's) adds `label` and `is_system`. `displayName()` returns the translation for system roles and `label` for custom ones.

## List

- Columns: name (+ "System" badge), technical name, user count, permission count ("All" for super-admin), actions.
- Counts come from `withCount(['users', 'permissions'])`.
- Row actions sit in a "⋮" dropdown (`<x-admin.row-actions>`): Edit (or View when the actor can't edit the role, and always for super-admin) and Delete.
- The role name links to the role page (edit or view mode) whenever the actor may view it.
- Delete only for custom roles. If the role has users, the dialog shows how many and has no confirm button.

## Form

- **Label:** required for custom roles, ≤255. System roles have no label field; their name is translated.
- **Technical name:** a slug of the label (transliterated) until edited by hand, `^[a-z0-9]+(-[a-z0-9]+)*$`, unique, set only at creation.
- **Permission matrix:** groups from the registry with translated labels and "Select all / Clear all" per group. Permissions the actor doesn't have are disabled, and select/clear only touch the grantable ones.
- **super-admin:** shows "has every permission" and nothing can be edited.

## Actions (`app/Actions/Roles`)

| Action | Permission | Protection rules |
|---|---|---|
| `CreateRole` | `roles.create` | permissions via `SyncRolePermissions` |
| `UpdateRole` | `roles.edit` | super-admin can't change (1); system roles keep their name (6) |
| `DeleteRole` | `roles.delete` | not super-admin (1), not a system role (6), not a role with users (7) |
| `SyncRolePermissions` | the caller authorizes | not super-admin (1); only permissions the actor has can be added (5) |

The rules are in `App\Support\Permissions\AccessRules`, and the permission checks are in `App\Policies\RolePolicy` (`assign()` decides which roles may be given to users). Spatie clears its cache on role and permission changes.

## Tests

`tests/Feature/Actions/Roles/*` (rules 1, 5, 6, 7), `tests/Feature/Admin/Roles/*` (pages, access, matrix, view mode), `tests/Feature/Console/SyncPermissionsTest.php`, `tests/Feature/Database/RoleSeederTest.php`, `tests/Feature/Support/Permissions/PermissionRegistryTest.php`.

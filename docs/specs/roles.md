# Roles and permissions page

Managing roles and assigning permissions to them. Permissions are declared in code: administrators can't create or delete them, only assign them to roles.

## Pages

| Page | Route | Name | Permission |
|---|---|---|---|
| List | `/admin/roles` | `admin.roles.index` | `roles.view` |
| Create | `/admin/roles/create` | `admin.roles.create` | `roles.create` |
| Edit | `/admin/roles/{role}/edit` | `admin.roles.edit` | `roles.edit`; with only `roles.view` it opens in view mode |

Components: `resources/views/pages/admin/roles/`. The create and edit pages extend `App\Livewire\Admin\RoleFormPage` (form plus permission matrix). The navigation item "Roles" is shown only with `roles.view`.

## Permissions

- **Registry:** `config/permissions.php` → `groups` (`resource => [actions]`, name `resource.action`). Modules extend it with `app(PermissionRegistry::class)->register('pages', [...])`.
- **Stage 1 permissions:** `admin.access`, `users.view|create|edit|delete`, `roles.view|create|edit|delete`.
- **Sync:** `php artisan permissions:sync` creates missing permissions and lists stale ones; `--prune` deletes them. It also clears the Spatie cache.
- **Labels:** `lang/*/permissions.php` → `groups.<resource>`, `items.<resource>.<action>`. A test checks that every permission has a label in every language.
- **Checks in code** always use permissions (`can('users.edit')`), never role names.

## System roles

| Role | Permissions | Note |
|---|---|---|
| `super-admin` | all, through `Gate::before` | can't be changed or deleted |
| `admin` | all registered (`*`) | |
| `editor` | `admin.access` | |
| `author` | `admin.access` | |

- Created by `RoleSeeder` (from `DatabaseSeeder`) with `is_system = true`. Reseeding only adds permissions, never removes them.
- Default permissions are in `config/permissions.php` → `roles`. Names are translated via `roles.names.<name>`.
- The `App\Models\Role` model (extends Spatie's) adds `label` and `is_system`. `displayName()` returns the translation for system roles and `label` for custom ones.

## List

- Columns: name (+ "System" badge), technical name, user count, permission count ("All" for super-admin), actions.
- Counts come from `withCount(['users', 'permissions'])`.
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

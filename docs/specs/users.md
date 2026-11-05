# Users page

Managing users in the admin panel: list, create, edit, delete.

## Pages

| Page | Route | Name | Permission |
|---|---|---|---|
| List | `/admin/users` | `admin.users.index` | `users.view` |
| Create | `/admin/users/create` | `admin.users.create` | `users.create` |
| Edit | `/admin/users/{user}/edit` | `admin.users.edit` | `users.edit` |

Admin group (`routes/admin.php`): `auth, verified, can:admin.access`. Components live in `resources/views/pages/admin/users/`, and each calls `authorize()` in `mount()` and in every action. The navigation item "Users" is shown only with `users.view`.

## List

- Columns: avatar, name (+ "You" badge), email, roles (translated badges), language, created date (`isoFormat('LL')`), actions.
- Search by name/email with a 300 ms debounce. It ignores case for Cyrillic too: it runs against the `users.search_index` column (lowercased name + email, kept current by the `HasSearchIndex` trait).
- Role filter, sorting by `name` / `email` / `created_at` (whitelisted), 25 per page.
- `search`, `role`, `sortBy`, `sortDirection` and `page` are kept in the URL (`#[Url]`).
- Empty states: "no users yet" and "nothing found".
- Roles are eager loaded (`with('roles')`).
- Deleting goes through `<x-confirm-modal>` with the user's name. The button is hidden when deletion isn't allowed.

## Form (create / edit)

Livewire Form object `App\Livewire\Forms\UserForm`, shared partial `partials/admin/user-form.blade.php`.

| Field | Create | Edit |
|---|---|---|
| Name | required, ≤255 | required |
| Email | required, unique, stored lowercase | required, unique |
| Password + confirmation | required, `Password::default()` | optional, only to change it |
| Language | `available_locales`, default `app.locale` | yes |
| Roles | checkboxes | checkboxes; locked on your own profile |
| Email verified | checkbox (sets `email_verified_at`) | shows the state only |

- Roles the actor can't assign are disabled, with an explanation.
- After saving: redirect to the list plus a toast (`Toast::success`).
- A protection-rule violation shows up as the `form.roles` error.

## Actions (`app/Actions/Users`)

| Action | Permission | Protection rules |
|---|---|---|
| `CreateUser` | `users.create` | roles via `SyncUserRoles` |
| `UpdateUser` | `users.edit` | only a super admin edits a super admin; roles via `SyncUserRoles` (only if `roles` is passed) |
| `DeleteUser` | `users.delete` | not yourself (4), not the last super admin (3), only a super admin deletes a super admin |
| `SyncUserRoles` | the caller authorizes | not your own roles (4), last super admin keeps the role (3), super-admin role only by a super admin (2), only roles whose permissions you all have (5) |
| `CreateSuperAdmin` | none (CLI and seeder) | — |

Each action takes `User $actor` plus validated data and runs in a transaction. A violation throws `ProtectedActionException` with a translated message. The rules themselves are in `App\Support\Permissions\AccessRules`, and the permission checks are in `App\Policies\UserPolicy`.

## Tests

`tests/Feature/Actions/Users/*` (protection rules), `tests/Feature/Admin/Users/*` (pages, access, search, filter, sorting, URL, N+1).

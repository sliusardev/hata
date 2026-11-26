# Users page

> The admin address is `config('hata.admin.path')` (env `HATA_ADMIN_PATH`, default `admin`); `/admin` in this spec stands for it. Route names stay `admin.*`.

Managing users in the admin panel: list, create, edit, delete.

## Pages

| Page | Route | Name | Permission |
|---|---|---|---|
| List | `/admin/users` | `admin.users.index` | `users.view` |
| Create | `/admin/users/create` | `admin.users.create` | `users.create` |
| Edit | `/admin/users/{user}/edit` | `admin.users.edit` | `users.edit` |

Admin group (`routes/admin.php`): `auth, verified, can:admin.access`. Components live in `resources/views/livewire/admin/users/`, and each calls `authorize()` in `mount()` and in every action. The navigation item "Users" is shown only with `users.view`.

## List

- Columns: avatar, name (+ "You" badge), email, roles (translated badges), language, created date (`isoFormat('LL')`), actions.
- Row actions (Edit, Delete) sit in a "⋮" dropdown (`<x-hata::admin.row-actions>`). Each item is shown only when the actor may perform it; with no items the button isn't rendered.
- The user's name links to the edit page when the actor may edit that user (same check as the Edit item); otherwise it's plain text.
- Search by name/email with a 300 ms debounce. It ignores case for Cyrillic too: it runs against the `users.search_index` column (lowercased name + email, kept current by the `HasSearchIndex` trait).
- Role filter, sorting by `name` / `email` / `created_at` (whitelisted), 25 per page.
- `search`, `role`, `sortBy`, `sortDirection` and `page` are kept in the URL (`#[Url]`).
- Empty states: "no users yet" and "nothing found".
- Roles are eager loaded (`with('roles')`).
- Deleting goes through `<x-hata::confirm-modal>` with the user's name. The menu item is hidden when deletion isn't allowed.

- **Extension points** (`docs/specs/admin-extensions.md`, target `users`): the columns after the name (email 10, roles 20, language 30, created 40 are Hata's own registry entries), filters after the role filter, "⋮" items after Edit and Delete, and bulk actions with row checkboxes (shown only when a bulk action is visible to the actor) are added from a site. A registered sort never replaces the whitelist: an unknown or hidden `sortBy` falls back to the default. **A bulk handler receives whoever was selected, including super admins and the actor**, so it applies `AccessRules` itself. A row action's permission is checked per user row and must not query.
- The "last super admin" rule in the list is decided from one count of super admins per render (`AccessRules::superAdminCount()`, passed to `userDeletionDenial()`), not a query per super-admin row.

## Form (create / edit)

Livewire Form object `Hata\Livewire\Forms\UserForm`, shared partial `partials/admin/user-form.blade.php`.

| Field | Create | Edit |
|---|---|---|
| Name | required, ≤255 | required |
| Email | required, unique, stored lowercase | required, unique |
| Password + confirmation | required, `Password::default()` | optional, only to change it |
| Language | `hata.admin.interface_locales`, default the site's interface default | yes |
| Roles | checkboxes | checkboxes; locked on your own profile |
| Email verified | checkbox (sets `email_verified_at`) | shows the state only |

- Roles the actor can't assign are disabled, with an explanation.
- After saving: redirect to the list plus a toast (`Toast::success`).
- A protection-rule violation shows up as the `form.roles` error.
- **Form cards** (target `users`, `docs/specs/admin-extensions.md`): a site's cards are drawn after the form's own blocks in one column and validated and saved with the form. `CreateUser`/`UpdateUser` call each card's `save()` with the user inside their transaction after the roles are synced, so a `ProtectedActionException` or a card's exception rolls everything back and no card value is stored. A card's `ValidationException` is shown under its fields.

## Actions (`src/Actions/Users`)

| Action | Permission | Protection rules |
|---|---|---|
| `CreateUser` | `users.create` | roles via `SyncUserRoles` |
| `UpdateUser` | `users.edit` | only a super admin edits a super admin; roles via `SyncUserRoles` (only if `roles` is passed) |
| `DeleteUser` | `users.delete` | not yourself (4), not the last super admin (3), only a super admin deletes a super admin |
| `SyncUserRoles` | the caller authorizes | not your own roles (4), last super admin keeps the role (3), super-admin role only by a super admin (2), only roles whose permissions you all have (5) |
| `CreateSuperAdmin` | none (CLI and seeder) | — |

Each action takes `AdminUser $actor` plus validated data and runs in a transaction. A violation throws `ProtectedActionException` with a translated message. The rules themselves are in `Hata\Support\Permissions\AccessRules`, and the permission checks are in `Hata\Policies\UserPolicy`.

## The user model

The site owns its `User` model and `auth.providers.users.model`; Hata never names the class. The host's model is

```php
class User extends Authenticatable implements AdminUser   // Hata\Contracts\AdminUser
{
    use HasFactory, IsAdminUser, Notifiable;                 // Hata\Models\Concerns\IsAdminUser
}
```

- `AdminUser` extends `HasLocalePreference` and Fortify's `PasskeyUser` and declares `isSuperAdmin()` and `initials()`; it is the type Hata takes and returns for users (`Authenticatable&AdminUser` in Eloquent generics).
- `IsAdminUser` brings `HasRoles`, `HasSearchIndex`, `PasskeyAuthenticatable`, `TwoFactorAuthenticatable`, `preferredLocale()` and the methods above, and adds Hata's fillable (`name, email, password, locale`; skipped for an unguarded host model, `$guarded = []`, which already fills every column), hidden (password, 2FA secrets, remember token, `search_index`) and casts (`email_verified_at`, `password` hashed) in `initializeIsAdminUser()` on top of the host's own; a cast the host declares for the same column wins.
- `Hata::userModel()` returns the configured class and throws a `LogicException` naming `IsAdminUser` unless it extends `Illuminate\Foundation\Auth\User`, implements `AdminUser` and uses `IsAdminUser` (checked once per class in a process). Queries, creation and relations (`Entry::author`, `Media::uploader`, `MaintenanceRun::user`) go through it.
- `UserPolicy` is registered for the configured class exactly (when it implements `AdminUser`), so a host policy Laravel would guess (`App\Policies\UserPolicy`) never replaces Hata's users checks, and for the `AdminUser` interface as the fallback (the Gate matches any class implementing it). Boot never throws for a host without the trait yet. The edit page receives the route's raw key and resolves it with the model's `resolveRouteBinding()` (the host's route key name; 404 when missing), since Livewire binds route models only to a class type.
- Hata's columns on `users` (2FA, `locale`, `search_index`) come from its own `add_*_to_users_table` migrations.

## Tests

`tests/Feature/Actions/Users/*` (protection rules), `tests/Feature/Admin/Users/*` (pages, access, search, filter, sorting, URL, N+1), `tests/Feature/Package/HostUserModelTest.php` (the configured model, the missing trait, the host's own fillable and casts, an unguarded host, a guessed host policy, a missing user).

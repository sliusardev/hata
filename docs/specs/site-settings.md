# Site settings page

Settings for the whole site that an administrator changes in the admin panel instead of editing `.env` (shared hosting often has no SSH).

## Page

| Page | Route | Name | Permission |
|---|---|---|---|
| Site settings | `/admin/settings` | `admin.settings.edit` | `settings.edit` |

Component: `resources/views/pages/admin/settings/⚡edit.blade.php`, form object `App\Livewire\Forms\SiteSettingsForm`. The navigation item "Site settings" (Administration group) is shown only with `settings.edit`. The `admin` system role gets the permission through `*`.

## Storage

- Table `settings` (`key` unique, `value` JSON), model `App\Models\Setting`.
- `App\Support\Settings\SiteSettings` (singleton) reads all rows through the cache (`site_settings`, forever) and writes each one over its config value in `AppServiceProvider::boot()`. The rest of the code keeps reading `config()`.
- A setting that isn't stored leaves the config value (from `.env`) in place. `put()` with `null` deletes the row and restores that value. Once saved, stored values win over `.env`.
- While the table doesn't exist yet (fresh install before `migrate`), no settings are applied.
- `put()` forgets the cache, re-applies the settings and drops resolved mailers, so a change works in the same request.

| Setting | Config | Section |
|---|---|---|
| `site_name` | `app.name` | General |
| `default_locale` | `app.locale` (read by `SetLocale`) | General |
| `timezone` | `admin.timezone` | General |
| `registration_enabled` | `admin.registration_enabled` | Access |
| `default_role` | `admin.default_role` | Access |
| `mail_mailer` | `mail.default` (only `smtp` is stored) | Email |
| `mail_host`, `mail_port`, `mail_scheme`, `mail_username`, `mail_password` | `mail.mailers.smtp.*` | Email |
| `mail_from_address`, `mail_from_name` | `mail.from.*` | Email |

## Tabs

The page has three tabs, "General", "Access" and "Email" (`flux:navbar` items, since Flux free has no tabs component). The open tab is in the query string (`?tab=general|access|mail`; anything else falls back to `general`). Each tab has its own "Save" button, which validates and stores only that tab's fields (`SiteSettingsForm::SECTIONS`), and redirects back to the same tab.

Switching tabs while the open one has unsaved changes (compared with the values loaded on mount; a typed SMTP password always counts) opens a confirmation (`<x-confirm-modal>`): "Cancel" stays on the tab, "Switch without saving" restores that tab's fields and opens the other one. Leaving the page itself is not guarded.

## Form

- **Site name:** required, ≤255.
- **Default language:** one of `app.available_locales`. For guests and users without a language.
- **Timezone:** any PHP timezone. Dates are stored in UTC (`app.timezone` doesn't change); admin views convert with `->tz(config('admin.timezone'))`.
- **Public registration:** switch. Fortify always registers the registration routes; `App\Http\Middleware\EnsureRegistrationIsEnabled` (in `fortify.middleware`) answers 404 while it's off, and the "Sign up" links check the setting.
- **Role of new users:** "No role" or any role except super-admin. Roles the actor can't assign (rule 5, `RolePolicy::assign`) are disabled. A warning is shown when the role has `admin.access`. `CreateNewUser` gives the role and never gives super-admin.
- **Email:** sender address (required, email) and name (required). Sending method "Server settings (.env)" or "SMTP server". With SMTP: host and port (1–65535) required, encryption STARTTLS (`smtp`) or SSL/TLS (`smtps`), username, password. The password is stored encrypted with `APP_KEY` (a row that can't be decrypted is ignored), is never sent to the browser, and an empty field keeps the stored one. Choosing the server method deletes every SMTP setting.
- **Send a test email** (Email tab only): to the current user's address with the saved settings (not the unsaved form). A transport error is shown in a danger toast.
- After saving: a full redirect back to the same tab (so the layout shows the new site name) with a success toast.

## Action (`app/Actions/Settings`)

| Action | Permission | Rules |
|---|---|---|
| `UpdateSiteSettings` | `settings.edit` | stores only the keys it's given (one tab); the role of new users must be assignable by the actor and not super-admin (`ProtectedActionException`, shown as a field error) |

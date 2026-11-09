# User settings

Account settings for any signed-in user. They don't require `admin.access`: users without access to the admin panel land here after logging in.

## Pages

| Page | Route | Name | Component |
|---|---|---|---|
| Profile | `/settings/profile` | `profile.edit` | `pages::settings.profile` |
| Language | `/settings/language` | `language.edit` | `pages::settings.language` |
| Security | `/settings/security` | `security.edit` | `pages::settings.security` |
| Appearance | `/settings/appearance` | `appearance.edit` | `pages::settings.appearance` |

Middleware: `auth`. Security also requires `password.confirm`. Routes are in `routes/settings.php`, sub-navigation in `resources/views/pages/settings/layout.blade.php`. The sidebar shows an "Account → My profile" item to every authenticated user (active on any `/settings/*` page).

## Profile

- Name and email (`ProfileValidationRules`). Changing the email resets `email_verified_at`.
- Deleting your own account (requires the password).

## Language

- A select from `config('app.available_locales')`, saved to `users.locale` (`string(10)`, nullable).
- After saving, a full redirect (no `wire:navigate`): the interface and `<html lang>` switch immediately. The toast goes through `App\Support\Toast` (session flash).
- The `SetLocale` middleware (web group) applies `users.locale` or `config('app.locale')`. An unknown value falls back to the default.
- `User` implements `HasLocalePreference`, so emails go out in the user's language.

## Security

- Password change, 2FA (TOTP plus recovery codes) and passkeys come from the starter kit (Fortify). Strings are translated, and vendor literals are mapped through `App\Support\Translation\VendorTranslations`.

## Appearance

- Light / dark / system (Flux `$flux.appearance`, stored in the browser).

## Tests

`tests/Feature/Settings/*`, `tests/Feature/Localization/SetLocaleTest.php`.

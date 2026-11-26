# User settings

Account settings for any signed-in user. They don't require `admin.access`: users without access to the admin panel land here after logging in.

## Pages

| Page | Route | Name | Component |
|---|---|---|---|
| Profile | `/settings/profile` | `profile.edit` | `hata::settings.profile` |
| Language | `/settings/language` | `language.edit` | `hata::settings.language` |
| Security | `/settings/security` | `security.edit` | `hata::settings.security` |
| Appearance | `/settings/appearance` | `appearance.edit` | `hata::settings.appearance` |

Middleware: `auth`. Security also requires `password.confirm`. Routes are in `routes/settings.php`, sub-navigation in `resources/views/components/settings/layout.blade.php`. The sidebar shows an "Account → My profile" item to every authenticated user (active on any `/settings/*` page).

## Profile

- Name and email (`ProfileValidationRules`). Changing the email resets `email_verified_at`.
- Deleting your own account (requires the password).
- **Form cards** (target `profile`, `docs/specs/admin-extensions.md`): a site's cards are drawn bare after the email field. Saving stores the user and the cards in one transaction; with no card the page is unchanged. The same card view serves the user form and registration. Registration (target `register`, Fortify's plain form, a guest) validates the cards with Hata's rules in one pass and creates the user, the default role and the cards in one transaction; a card whose permission is an ability string is never shown to a guest.

## Language

- A select from `config('hata.admin.interface_locales')`, saved to `users.locale` (`string(10)`, nullable).
- After saving, a full redirect (no `wire:navigate`): the interface and `<html lang>` switch immediately. The toast goes through `Hata\Support\Toast` (session flash).
- The `SetLocale` middleware (web group) applies, for a signed-in user, `users.locale` else the site's default interface language (`ContentLocales::interfaceDefault()`), and for a guest the last visited site language else the default. An unknown value falls back to the default.
- `User` implements `HasLocalePreference`, so emails go out in the user's language.

## Security

- Password change, 2FA (TOTP plus recovery codes) and passkeys come from the starter kit (Fortify). Strings are translated, and vendor literals are mapped through `Hata\Support\Translation\VendorTranslations`.

## Appearance

- Light / dark / system (Flux `$flux.appearance`, stored in the browser).

## Tests

`tests/Feature/Settings/*`, `tests/Feature/Localization/SetLocaleTest.php`.

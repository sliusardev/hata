# Site settings page

Settings for the whole site that an administrator changes in the admin panel instead of editing `.env` (shared hosting often has no SSH).

## Page

| Page | Route | Name | Permission |
|---|---|---|---|
| Site settings | `/admin/settings` | `admin.settings.edit` | `settings.edit` |

Component: `resources/views/pages/admin/settings/⚡edit.blade.php`, form object `App\Livewire\Forms\SiteSettingsForm`. The navigation item "Site settings" (Administration group) is shown only with `settings.edit`. The `admin` system role gets the permission through `*`.

## Storage

- Table `settings` (`key` unique, `value` JSON), model `App\Models\Setting`.
- `App\Support\Settings\SiteSettings` (singleton) reads all rows through the cache (`site_settings`, forever) and writes each one over its config value in `AppServiceProvider::boot()`. The rest of the code keeps reading `config()`. A queue worker boots once, so `Queue::before` (also in `AppServiceProvider`) applies them again before every job, and `media:optimize` before every batch (decision 48); `apply()` first restores the config values of settings applied earlier, so a setting removed meanwhile falls back to `.env` there too.
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
| `color_scheme`, `color_accent`, `color_gray`, `color_tint_sidebar` | `admin.colors.*` | Colors |
| `media_optimize`, `media_max_side`, `media_quality`, `media_format`, `media_strip_exif`, `media_client_resize`, `media_keep_originals` | `media.optimize.*` | Media |
| `media_max_image_mb`, `media_max_document_mb` | `media.limits.image_mb`, `media.limits.document_mb` | Media |
| `logo_light`, `logo_dark` | `admin.logo.light`, `admin.logo.dark` (paths on `admin.logo.disk`) | Logo |

## Tabs

The page has six tabs, "General", "Access", "Email", "Logo", "Colors" and "Media" (`flux:navbar` items, since Flux free has no tabs component). The open tab is in the query string (`?tab=general|access|mail|logo|colors|media`; anything else falls back to `general`). Each tab has its own "Save" button, which validates and stores only that tab's fields (`SiteSettingsForm::SECTIONS`), and redirects back to the same tab.

Switching tabs while the open one has unsaved changes (compared with the values loaded on mount; a typed SMTP password always counts) opens a confirmation (`<x-confirm-modal>`): "Cancel" stays on the tab, "Switch without saving" restores that tab's fields and opens the other one. Leaving the page with unsaved changes is guarded in the browser (Alpine compares `$wire.form` with `savedValues`, so it also sees edits not yet sent to the server): a `wire:navigate` link (the sidebar) is cancelled through the `livewire:navigate` event and a confirmation "Leave without saving" opens; closing or reloading the tab and plain links get the browser's own `beforeunload` dialog. The browser's Back button is not guarded (`livewire:navigate` with `history: true` arrives after the URL has already changed). A successful save marks the form clean before its redirect.

## Form

- **Site name:** required, ≤255.
- **Default language:** one of `app.available_locales`. For guests and users without a language.
- **Timezone:** any PHP timezone. Dates are stored in UTC (`app.timezone` doesn't change); admin views convert with `->tz(config('admin.timezone'))`.
- **Public registration:** switch. Fortify always registers the registration routes; `App\Http\Middleware\EnsureRegistrationIsEnabled` (in `fortify.middleware`) answers 404 while it's off, and the "Sign up" links check the setting.
- **Role of new users:** "No role" or any role except super-admin. Roles the actor can't assign (rule 5, `RolePolicy::assign`) are disabled. A warning is shown when the role has `admin.access`. `CreateNewUser` gives the role and never gives super-admin.
- **Email:** sender address (required, email) and name (required). Sending method "Server settings (.env)" or "SMTP server". With SMTP: host and port (1–65535) required, encryption STARTTLS (`smtp`) or SSL/TLS (`smtps`), username, password. The password is stored encrypted with `APP_KEY` (a row that can't be decrypted is ignored), is never sent to the browser, and an empty field keeps the stored one. Choosing the server method deletes every SMTP setting.
- **Send a test email** (Email tab only): to the current user's address with the saved settings (not the unsaved form). A transport error is shown in a danger toast.
- After saving: a full redirect back to the same tab (so the layout shows the new site name) with a success toast.

## Logo

- **Default mark:** `<x-app-logo-icon>`, a house with crossed rafters over the gable and a four-pane window (`currentColor`). `public/favicon.svg` (follows the system theme), `favicon.ico` and `apple-touch-icon.png` use the same mark. Uploaded logos don't change the favicon.
- **`<x-site-logo class="h-8">`** shows the uploaded logos (light one with `dark:hidden`, dark one with `hidden dark:block`) or the default mark on an accent tile. Used by `<x-app-logo>` (sidebar, header) and the auth layouts; `variant="dark"` always shows the dark one (the dark panel of the split auth layout).
- **Logo tab:** a light and a dark slot, each with a preview on a matching background, a file input and "Remove". PNG, JPG, WebP or SVG up to 1 MB. A file is stored as soon as it's uploaded (no Save button on this tab), then the page reloads on the same tab with a toast.
- **Fallback:** with only one logo uploaded, it's used in both themes (`SiteLogo::path()`).
- **Storage:** `UploadLogo` stores the file in `branding/` on `admin.logo.disk` (default `local`, private) and deletes the file it replaces; `RemoveLogo` deletes the file and the setting.
- **Serving:** `GET /branding/logo/{light|dark}` (`branding.logo`, public, because the sign-in pages show it), `SiteLogoController`. 404 without a logo. The URL carries `?v=` (hash of the path), so the response is cached for a year (`immutable`). `Content-Security-Policy: … sandbox` and `nosniff` keep scripts in an uploaded SVG from running when it's opened directly; inside `<img>` they never run.

## Colors

- **Scope:** the admin panel and the sign-in pages (every layout that includes `partials/head`). Public themes come later.
- **Presets** (`ColorScheme::PRESETS`, key → company color + base gray): neutral (stock look, no overrides), blue, indigo, violet, rose, orange, amber, emerald, teal.
- **Custom** (one per site): company color (`#rrggbb`, color picker + text field) and base gray (neutral, zinc, slate, stone, gray). The company color is kept when a preset is chosen, so switching back restores it.
- **Tint the sidebar** (on by default, hidden for neutral): light shade (step 50, border 100) in the light theme, deep shade (950, border 900) in the dark theme; off uses the base gray.
- **How it works:** `App\Support\ColorScheme\ColorScheme` builds an 11-step accent scale from the company color in OKLCH (`Oklch`, fixed lightness per step, chroma scaled, clipped to sRGB) and writes CSS variables in `partials/color-scheme` (an inline `<style id="color-scheme">` after the Vite CSS): `--color-zinc-*` (base gray; Flux uses zinc everywhere), `--color-accent`, `--color-accent-content`, `--color-accent-foreground`, `--color-sidebar`, `--color-sidebar-border`, for `:root` and `.dark`. No CSS rebuild. `resources/css/app.css` defines the sidebar variables for the stock look; the layouts use `bg-sidebar` / `border-sidebar-border`.
- **Rules:** light accent = the company color; light accent text = the first of it or steps 600–900 with 4.5:1 on white. Dark accent = the company color if it is already light (OKLCH L ≥ step 500), otherwise step 500; dark accent text = the first readable (4.5:1) on gray 800. Text on accent buttons = white while contrast ≥ 3:1 (WCAG for UI), otherwise step 950.
- **Role badges** in the user list use the accent (`bg-accent/10`, `text-accent-content`); super-admin stays amber.
- **Preview:** the Colors tab renders the form's scheme in an inline `<style>` (including the stock values, so "Neutral" previews over a saved scheme). Saved with the tab's Save button, guarded by the unsaved-changes warnings like the other tabs.

## Media

Details of the settings and their defaults are in [the media spec](media.md#site-settings--media-tab-screen-7).

- **Types in config:** `SiteSettings::CASTS` makes int settings ints and boolean settings real bools when they are applied to config (`OptimizeImage` checks `is_int(max_side)`). "Do not scale" is stored as `0` (a `null` would delete the row and bring back the default 2560) and applied as `null`.
- **Fields:** switches for the booleans, a native select for the longest side (1920, 2560, 3840, none), a range input for quality (50-100) with its value shown, radios for the format. The optimization options are hidden while "Optimize images" is off.
- **Format:** only `webp` and `original` can be saved. AVIF is listed disabled in Part 1: "not supported by the server" when the driver can't encode it (`ServerCapabilities::supportsAvif()`, a 1x1 test encode cached for a day under `media.avif_supported`), otherwise "not available yet".
- **Limits:** 1-512 MB each. The tab shows the server cap, `min(upload_max_filesize, post_max_size)` from `ServerCapabilities::uploadCapBytes()` (bound in the container, tests replace it). A limit above the cap is a field error (`media.errors.limit_above_server`), checked only when the value differs from the one in effect, so the untouched defaults (30/20 MB) never block saving on a server with a low cap (e.g. PHP's default 2 MB); the cap line stays as the warning. No cap is shown when PHP sets none.
- **Optimize existing card:** shown below the optimization options for users with `media.edit`: the number of stored images that don't match the saved settings (`Media::optimizationCandidates()` with `OptimizeImage::fingerprint()`, not the unsaved form) and the estimated saving (85% of the size of never-optimized files plus 20% of the rest, two SQL sums), or the progress of a running or paused run ("Paused: N of M files"; a stale run is ignored), or, while optimization is stored as off (the saved setting, not the form switch), only a "save to see" hint with no counts or button, or "All images match the current settings"; the button opens `admin.media.optimize`. The queries run only on the Media tab.

## Action (`app/Actions/Settings`)

| Action | Permission | Rules |
|---|---|---|
| `UploadLogo` | `settings.edit` | stores the validated file, deletes the previous one |
| `RemoveLogo` | `settings.edit` | deletes the file and the setting |
| `UpdateSiteSettings` | `settings.edit` | stores only the keys it's given (one tab); the role of new users must be assignable by the actor and not super-admin (`ProtectedActionException`, shown as a field error) |

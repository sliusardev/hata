# Media library, Part 4: media picker — design

> Status: built. Part 4 is built (media picker, logos from the library); the current behaviour is in `docs/specs/media.md` ("Picker") and `docs/specs/site-settings.md` ("Logo").

Date: 2026-11-11. Builds on `docs/specs/media.md` ("Picker (screen 5)", `media_usages`) and `docs/specs/site-settings.md` ("Logo"). Approved mockup for the picker: screen 5 of https://claude.ai/artifact/4ht3hKQ37R8oawQZFBCoi4. Read those first; this document settles what they leave open.

## Intent

Like WordPress: wherever an admin form needs an image (or a file), a modal with the media library opens to pick an existing file or upload a new one (user's words). One reusable component, so pages, posts and themes later get it for free. Its first real use is the site logo (user's decision), which today is a separate upload on its own disk.

## Goals

- A picker component any admin form can put next to a field: the field holds a media id (or ids).
- In the modal: tab "Library" (search, month filter, compact folders, like the library page) and tab "Upload new" (same upload pipeline as the library: browser resize, server optimization, folder); a freshly uploaded file is selected right away.
- Side panel with the selected file's preview and alt text; editing alt there changes the file in the library and the modal says so. Double click selects and closes.
- Options per use: `accept` (`image` / `document` / `any`), `multiple`.
- The site logo (light and dark slots) is picked from the library instead of uploaded to `branding/`.
- `media_usages` gets its first real rows (the logo), and "Where it's used" shows them with a readable label and a link.

## Non-goals

- Inserting images into rich text (there is no editor yet). The picker must not prevent it later, but nothing for it is built now.
- Pages, posts, themes, galleries.
- Changing the favicon from the logo (unchanged: uploaded logos don't change the favicon).

## Anti-goals

- A site that shows a broken logo: a deleted, missing or non-image logo file must fall back the way a missing logo does today.
- Losing a logo already uploaded before this change.
- A second upload pipeline or a second file-serving route for logos: logos are ordinary media files.
- A picker that lets someone do through the modal what their permissions don't allow on the library page.

## Decisions

### The picker component

- A Livewire component placed next to a form field; the chosen id(s) go back to the parent's field (`wire:model`-style binding or an event the parent handles — builder's call, but one way used everywhere).
- Opens in `flux:modal` (Flux free). Tabs as in the spec.
- Folders in the Library tab look like the library (user's decision, 2026-11-11, after trying the picker): subfolders of the open folder as tiles above the files, a click opens the folder, a breadcrumb leads back; the same semantics as the library page's subfolder tiles (no drag, no folder menus in the picker). Uploads go into the open folder.
- The form side shows the current choice: thumbnail, name, "Change" and "Remove" (one file), or a list for `multiple`.
- Permissions are the library's: the modal needs `media.view`; the "Upload new" tab needs `media.upload`; the alt field is read-only without `media.edit`. Without `media.view` the form shows the current choice and "Remove" only, with a hint that choosing needs access to the media library (my call).
- The parent form must validate the returned id(s) itself on save (exists, kind matches `accept`): the browser can send any id.
- Optional narrower filter (my call): an `extensions` option (e.g. `['png', 'jpg', 'jpeg', 'webp', 'svg']` for the logo). Library files outside it are shown disabled with the reason ("This format can't be used here"), uploads outside it are refused in the "Upload new" tab, and the parent re-checks on save.
- With `multiple`, the value is an ordered list of ids (my call): the order the files were picked in is kept; no drag-reordering yet and no order column in `media_usages`.

### The logo through the library (user's decision)

- Settings `logo_light` / `logo_dark` hold a media id instead of a path.
- Logo tab: each slot has "Choose from library" (picker, `accept` = image, single; PNG, JPG, WebP or SVG as today) and "Remove". Choosing saves at once (as uploads do today: toast, the tab reloads). "Remove" unlinks the file; the file stays in the library.
- `<x-site-logo>` renders the media file's URL (with its `?v=`, so a crop or replace shows up). The "only one logo → used in both themes" fallback stays (`SiteLogo`).
- A logo that points at a missing row, a missing file or a non-image falls back as "no logo", never a broken image. One unusable slot counts as empty, so the other slot is used in both themes (the existing one-logo rule); only with both unusable does the default mark show (my call).
- The logo's `alt` stays the site name (`config('app.name')`), not the media file's alt (my call).
- The logo slots accept PNG, JPG/JPEG, WebP and SVG only (the `extensions` option), as today.
- Removed: the `branding/` storage on `admin.logo.disk`, `UploadLogo`, the `branding.logo` route and `SiteLogoController`. The media route already sends `nosniff` and the CSP sandbox for SVG (decision 41).
- Upgrade (my call): logos already uploaded to `branding/` are imported into the media library once (a migration or an artisan step run by the migration — builder's call; it must work without SSH, i.e. through `php artisan migrate` run by the deploy) and the settings rewritten to their ids. A file that can't be imported leaves that slot empty (default mark), never an error that blocks the migration.
- The logo tab's existing 1 MB limit: the library's image limit applies instead (my call), since the file is an ordinary media file now.

### Usage tracking

- Choosing a logo records a `media_usages` row (the `Setting` row of `logo_light` / `logo_dark` as the usable, field = the setting key); removing or replacing it removes/updates that row (`MediaUsage::sync`).
- "Where it's used" in the details panel and the delete dialogs show a readable label with a link instead of "Type #id": for the logo, "Site logo (light)" / "Site logo (dark)" linking to the settings logo tab. The mechanism must let later usables (pages, posts) name themselves the same way.

### Deleting a file that is in use (user's decision)

Allowed, like WordPress, with a warning: the delete dialog (single, bulk, folder) says where the file is used, by label (e.g. "used as the site logo"). After deletion the site falls back to the default mark; the usage rows go with the file, and deleting a file that is a logo also clears `logo_light` / `logo_dark` so the setting doesn't keep a dangling id (my call; rendering still falls back for any other dangling id).

The "Site logo" usage label links to the settings logo tab only for someone with `settings.edit`; others see plain text (my call).

## Constraints

- Flux free only; translations in `lang/en` and `lang/uk`; specs updated in the same commits (`docs/specs/media.md`, `docs/specs/site-settings.md`); a new CLAUDE.md decision replaces/updates decision 39 (logos).
- Shared hosting: no `storage:link`, no SSH for the upgrade step.
- Tests on SQLite and MySQL.

## Left to the builder

- Component name, binding mechanism, and how much of the library page's Livewire/Alpine code is shared versus re-used through partials (prefer sharing; no copy-paste of the library's query logic).
- The modal layout within screen 5 of the mockup.
- How a usable names itself for "Where it's used" (an interface on the model, a resolver map, …).
- Import mechanics for existing logos (folder for them, file names).

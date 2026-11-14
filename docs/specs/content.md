# Content

Content types (pages now, posts later) and their extra fields. The registry, the record tables and models, the
slug and path rules, the body sanitizer, the save actions, the trash, the admin list, the record form and the public pages exist so far. Design: `docs/superpowers/specs/2026-11-14-content-types-design.md`.

## Content types

`App\Support\Content\ContentType` is the base of one kind of content. A type is a small class:

| Method | Meaning |
|---|---|
| `key()` | the stored type name, the admin address segment and the permission group (`pages`) |
| `label()`, `pluralLabel()` | translated names, from `content.types.<key>.label` / `.plural` by default |
| `icon()` | icon name for navigation |
| `hierarchical()` | records form a tree with a parent and a path |
| `base($locale)` | first address segment of the type in a language, or null for the root |
| `fields()` | the extra fields (none by default); `field($name)`, `sharedFields()`, `localFields()` |
| `description()` | the line under the admin list's heading, from `content.types.<key>.description` |
| `createLabel()` | the list's "Create" button ("Create page"), from `content.types.<key>.create` |
| `newLabel()` | the heading of a new record's form ("New page"), from `content.types.<key>.new`, else `createLabel()` |
| `perPage()` | admin list page size, 20 by default |
| `permission($action)` | `"{key}.{action}"` |

`App\Support\Content\ContentTypes` (singleton) holds the registered types: `register()`, `all()`, `find()`,
`get()` (throws on an unknown key) and `pattern()`, an alternation of the keys for route constraints (it
matches nothing while no type is registered). Keys follow the permission segment format. `register()` also
registers the type's `view`, `create`, `edit` and `delete` (`ContentTypes::ACTIONS`) with `PermissionRegistry`, so
a type registered at any time (a module's, or a test's) is seen by `permissions:sync` and the role matrix.

`App\Providers\ContentServiceProvider` binds the singleton and registers `PagesType` (key `pages`,
hierarchical, no base, no fields). Its `boot()` builds the registry, so the kit's types have registered their
permissions before anything reads them. Publishing and restoring
need `edit`; trashing and deleting for good need `delete`. The `editor` role gets the four `pages.*`
permissions by default; `admin` gets them through `*`.

`config/content.php` → `trash_days` (30): how long a trashed record is kept (`PruneEntryTrash`).

## Fields

`App\Support\Content\Fields\Field` (`Text`, `Textarea`, `RichText`, `Number`, `Date`, `Toggle`, `Select`,
`MediaFile`, `Gallery`) is built with `Field::make($name)` and chained `label($key)`, `shared()` (the value is
the same in every language of a record group) and `required()`. `Select` adds `options()`.

| Method | Meaning |
|---|---|
| `rules()` | validation rules for the value (`required` or `nullable`, then the kind's own) |
| `toStorage($formValue)` | text trimmed, empty becomes null; number numeric; date `Y-m-d`; toggle bool; media id int; gallery list of ints; rich text cleaned by `BodySanitizer::clean()` (null for an empty document) |
| `toForm($stored)` | the value as the form binds it (null becomes `''` for text, number, date and select) |
| `view()` | Blade partial name under `partials.admin.content.fields`; the partials come with the editor |
| `mediaIds($stored)` | media ids a value refers to; empty except for `MediaFile`, `Gallery` and `RichText` (its images, `BodySanitizer::mediaIds()`) |
| `holdsMedia()` | true for `MediaFile`, `Gallery` and `RichText`: their usages are recorded |
| `withoutMedia($stored, $id)` | the value without a file being deleted: a file field emptied, the id taken out of a gallery, the images removed from rich text (`BodySanitizer::withoutMedia()`) |

A rich text field follows the body's rules: the same allowlist on save (so it may be printed unescaped), at most
`EntryInput::BODY_MAX_BYTES` as sent (`content.entry.body_too_long`), and its images recorded as usages (only files
that exist, as for the body). A toggle has no required state: it is always true or false. A malformed date is stored as null (its rule reports
it). `MediaFile` takes a file of any kind unless narrowed with `accept('image')` or `accept('document')`; `Gallery`
takes images only. Both validate with `App\Rules\MediaSelection`, so every id, each gallery item included, must be
an existing file of the right kind.

## Records

Tables: `entry_groups` (`type`, `shared` JSON), `entries` and `entry_redirects`. Columns as in the design. Lengths:
`type` 40, `locale` 12, `title`, `slug` and `path` 255. Unique indexes: (`entry_group_id`, `locale`) and
(`type`, `locale`, `path`); (`type`, `locale`, `status`, `published_at`) and `parent_id` are plain indexes. The
path index is 307 characters wide, which fits MySQL's limit on utf8mb4 (checked on MySQL 8). Deleting a user
nulls `author_id`, deleting a media file nulls `image_id`, deleting a parent nulls `parent_id`, deleting an entry
for good removes its redirects.

`App\Models\EntryGroup`: casts `shared` to an array; `entries()`. `App\Models\EntryRedirect`: `entry()`.

`App\Models\Entry` (soft deletes are the trash; `HasSearchIndex` from title and slug):

| Member | Meaning |
|---|---|
| `group()`, `parent()`, `children()`, `author()`, `image()` | relations |
| `groupEntries()` | relation: every record of the group, this one included, trashed left out; eager load it in lists |
| `translations()` | method, not a relation: the other records of the group, taken from the loaded `groupEntries` when there is one (no query per record) |
| `ofType($type)`, `inLocale($locale)` | scopes |
| `public()` | scope: `published`, `published_at <= now()`, not trashed |
| `type()` | the registered `ContentType` |
| `state()` | `EntryState`: `Draft` (also a published record with no date), `Scheduled` (published with a future date) or `Published`; derived, never stored |
| `isPublic()` | the same rule as `public()` for one record |
| `depth()` | slashes in `path` (0 at the top) |
| `descendants()` | query of the records under this one: same type and language, `path` starting with this path and `/` (live ones unless the caller adds `withTrashed()`/`onlyTrashed()`) |
| `descendantsCount()` | how many descendants are in the record's own state, one query; for the confirmation text. For a live record the live ones (what trashing takes along); for a trashed record every trashed record under its path, which is exactly what deleting it for good removes besides itself |
| `url($absolute = true)` | the site address from the `content` route (`ContentLocales::route()` in the record's language): the language prefix (none for the default content language, `/<code>` for the others), the type's base for that language, then `path`. No query |
| `fieldValue($name)` | a shared field's value from the group's `shared`, any other from `fields` |

`App\Enums\EntryStatus` (`Draft`, `Published`) is stored; `EntryState` is not.

Factories: `Entry::factory()` makes a draft in `pages` and `en`, with its own group and a `path` taken from the
parent. States: `page()`, `draft()`, `published()` (a day ago), `scheduled()` (a day ahead), `inLocale()`,
`childOf($parent)` (same type and language, path under the parent) and `translationOf($source, $locale)` (same
group and type).

## Slugs, paths and redirects

`App\Support\Content\EntryPaths` (resolved from the container):

| Method | Meaning |
|---|---|
| `slugFrom($title)` | `Str::slug`; may be empty |
| `suggest($type, $locale, $parent, $title, $ignore = null)` | the generated slug with `-2`, `-3`... added while its path is taken. Taken means a record of the same type and language holds the path, trashed or not, or the slug is reserved at the top of the address (`isReserved()`). One query. A path held only by a redirect row is free |
| `pathFor($type, $parent, $slug)` | the parent's path and the slug |
| `move($entry, $newPath, $keepOldPaths = true)` | for the caller's transaction: sets the record's path, rewrites the path of every descendant (same type and language, trashed included, matched by the old path followed by `/`) with one `UPDATE`, deletes the redirect rows whose path a record now holds, and stores every old path in `entry_redirects` with one upsert (an existing row for that path is pointed at the record). Five queries whatever the number of descendants. With `$keepOldPaths = false` no redirect is stored (a record restored from the trash). The SQL concat is the one driver-specific part (`||` on SQLite, `CONCAT` on MySQL) |
| `isReserved($type, $locale, $parent, $slug)` | the one answer to "reserved at the top of the address": no parent, a type with no base, and the slug is in `ReservedSlugs` or is the base of any registered type in that language. Used by both `suggest()` and `EntrySlug` |

`App\Rules\EntrySlug($type, $locale, $parent, $ignore)` checks, in this order: the format
`^[a-z0-9]+(-[a-z0-9]+)*$`; not reserved (only for a record at the top of the address, that is without a parent
and in a type with no base: `ReservedSlugs`, or the base of any registered type in that language); the full path
at most 255 characters; not taken by another record of the type and language, trashed ones included, the record
being edited excepted. Messages are `content.slug.*`. An empty value is left to `required`.

## Body

A record's body is HTML from the rich text editor. It is sanitized once on save and printed unescaped, so the
sanitizer is the security boundary. Package: `symfony/html-sanitizer` (parsed with PHP's `Dom\HTMLDocument`).

`App\Support\Content\BodySanitizer`:

| Method | Meaning |
|---|---|
| `clean($html)` | allowlist: `p`, `h2`-`h4`, `strong`, `em`, `u`, `s`, `ul`, `ol`, `li`, `blockquote`, `br`, `hr`, `code`, `pre`, `a` (`href`, `target`) and `img` (`data-media-id`, `alt`). Links: `http`, `https`, `mailto`, `tel` and relative addresses only; the sanitizer checks the scheme after decoding entities and ignoring case and whitespace. Any other attribute (`style`, `class`, `on*`, `src`) is dropped. `data-media-id` must be a positive whole number, else it is dropped, and an `img` left without one is removed. Unknown elements keep their text but lose their tags; `script`, `iframe`, `object`, `embed`, `svg`, `math`, `form`, `template`, `noscript`, `textarea`, `select`, `style`, `title` and the other head elements are removed with their contents. A link keeps `target` only as `_blank` and then always gets `rel="noopener noreferrer"`; a link without a target has no `rel`. A `pre` keeps its leading newline across repeated saves. `xmp`, `noembed`, `noframes` and `plaintext` are removed with their contents too. The result is null when it has no text (whitespace and non-breaking spaces ignored), no `img` and no `hr`, so the editor's empty `<p></p>` is an empty body. The input is cut to `BodySanitizer::MAX_LENGTH` (4 000 000 bytes, before any parsing; the form must validate against it) instead of the package's default of 20 000 |
| `mediaIds($html)` | ids of the image nodes, in order, each once |
| `withoutMedia($html, $id)` | the body without that file's images; null when nothing is left. For the delete of a media file |

Images are stored as `<img data-media-id="12" alt="...">`, never with an address.
`App\Support\Content\BodyRenderer::render($html)` returns an `HtmlString`: each image gets `src` from
`Media::url()` (already carrying `?v=`), `width` and `height` when the file has them, `loading="lazy"` and
`decoding="async"`; its `alt` is the one stored in the body, else the file's alt in the request's language. An
image whose file is gone or is not an image is dropped. One `whereIn` query for a body with images, none without.
It does not sanitize again: it trusts that the body was cleaned on save.

## Editor

`<x-admin.rich-text wire:model="form.body" :label="…" :images="$images" :locale="$entry->locale" :readonly="false" />`
(`resources/views/components/admin/rich-text.blade.php`) is a Tiptap 3 editor (npm `@tiptap/core`, `@tiptap/pm`,
`@tiptap/starter-kit`, pinned to 3.34.0; approved by the user) driven by the Alpine component `richText`
(`resources/js/rich-text-field.js`, registered in `app.js`). The Tiptap code (`resources/js/rich-text.js`) is loaded
with `import()` when an editor initializes, so it is a chunk of its own (about 386 KB, 122 KB gzipped) that only pages
with an editor download; the `app` entry grew by the Alpine component only (19.3 KB to 22.6 KB). Until it loads, the
box shows a skeleton; if it fails, a message asks to reload.

- **What it produces** is exactly what `BodySanitizer::clean()` keeps: the starter kit (paragraph, headings limited to
  levels 2-4, bold, italic, underline, strike, inline code, link, bullet and numbered lists, blockquote, hard break,
  horizontal rule, code block, undo/redo) plus the node `mediaImage`, `<img data-media-id alt>`. The schema reduces
  pasted content to that set (an `h1` or `h5` becomes a paragraph, an image without a valid `data-media-id` is
  dropped), and pasted links keep only `href` and `target="_blank"`, pasted numbered lists lose `start` and `type`.
  Links accept `http`, `https`, `mailto`, `tel` and relative addresses (the same rule as the server); the link form
  adds `mailto:` to an email and `https://` to an address that starts with `www.` or looks like a host
  (`example.com/page`, but not a file name such as `page.html`, `index.php` or `report.pdf`); anything starting with
  `/`, `#`, `?`, `.` or a scheme is kept as typed; a host with a port (`example.com:8080/x`) is a host and gets
  `https://` (the scheme pattern alone would read `example.com:` as a scheme), while `tel:123` stays a scheme. A numbered list always counts from 1 in the editor
  (CSS), as it will once the server has dropped `start`. Harmless leftovers the server drops anyway: an image's `src`,
  a code block's `language-*` class.
- **Binding.** The component reads the property name from `wire:model` and does not put `wire:model` on the page.
  The editor reads the property once (`$wire.get()`) when it loads and then writes it without a request
  (`$wire.set(name, html, false)`), 300 ms after the last edit and again synchronously whenever the Livewire
  component starts any action while an edit is still unwritten (`$wire.$intercept`, which runs before Livewire
  collects the property updates), so a save right after typing carries the latest HTML and an untouched body is never
  sent back. An edit is an `update` whose root transaction changed the document (`isContentEdit()`): a focus or
  click on a body that ends with an image, heading, list, quote, code block or rule makes the starter kit's trailing
  node plugin append an empty paragraph, and Tiptap emits `update` for that appended change; it is neither written
  back nor counted as an edit. The first edit after a write dispatches the bubbling browser event
  `rich-text:changed` (`{model}`), the change signal a form's leave-page warning uses. An empty document is `''`. The editor's markup is in `wire:ignore`:
  a re-render never resets it or takes the focus, and a value the server writes into the property is not shown.
- **Toolbar** (hidden when `readonly`): text style (paragraph, heading 2-4), bold, italic, underline, strike, bullet
  and numbered list, quote, link, image, undo, redo. Buttons have `aria-label`s and Flux tooltips, toggles carry
  `aria-pressed` and an active look; the toolbar wraps on a phone. Ctrl/Cmd+K opens the link form.
- **Link form**: an inline strip (not a `<form>`, since the editor sits inside the page's form) with the address, "Open
  in a new tab", Apply, Remove link and Cancel; Enter applies, Escape closes. With nothing selected the address is
  inserted as the link's text and the next typed character is plain text.
- **Images**: the image button (only with `media.view`) dispatches the browser event `media-picker:open` with
  `{target: 'rich-text:<property>', locale}`; the page's one detached media picker answers with `media-picker:picked`
  and only the editor with that target inserts the image at its selection, or after the selected image (`src` from
  the event's `url`). For a body
  opened again, `images` maps each media id to its current URL and fills the `src` when the content loads; an image
  with no URL shows as a neutral block. Selecting an image shows an "Image alt text" field that edits that image's
  `alt` in the body (not the library's alt). The page must mount `<livewire:media-picker detached accept="image" />`
  once; see the media spec's Picker section.
- **Look**: one bordered rounded box like the other fields, toolbar on a tinted strip with separators, the text below
  with at least 16rem and growing; the focus ring of the other fields while the text has the focus; zinc and accent
  only. Body typography is the `.rich-text-content` class in `resources/css/app.css`, shared with the public page; the editing box (padding, minimum height, a smaller size) is `.rich-text-content.ProseMirror`.

## Saving records

`App\Support\Content\EntryInput` (static) holds the form's rules and turns the data into what is written.
`rules($type, $locale, $entry)` and `validate($type, $locale, $entry, $data)`:

| Key | Rule |
|---|---|
| `title` | required, at most 255 |
| `slug` | required, `EntrySlug` under the parent the data names (not checked while that parent is invalid); for a saved record whose slug changes, no descendant (trashed ones included) may get a path over 255 characters (`content.slug.descendants_too_long`; one query for the longest descendant path) |
| `parent_id` | hierarchical type: nullable, a record of the same type and language, not trashed, neither the record itself nor one of its descendants (by path), `content.entry.parent_invalid`; when only the parent changes, the descendants' paths must stay within 255 characters (`content.entry.descendants_too_long`); flat type: prohibited |
| `author_id` | nullable, one of `EntryAuthors`, or the record's current author (who may have lost the permission since) |
| `image_id` | nullable, `MediaSelection('image')` |
| `status` | required, `draft` or `published` (the stored status; a future date with `published` schedules) |
| `published_at` | nullable date, read in the site's display timezone (`admin.timezone`) and returned in UTC (decision 38) |
| `excerpt` / `seo_title` / `seo_description` | at most 1000 / 255 / 500; trimmed, empty becomes null |
| `body` | nullable string of at most `EntryInput::BODY_MAX_BYTES` (1 000 000 bytes, under the sanitizer's cut), so a long body is a field error, never cut |
| `fields.<name>` | each field's own `rules()` |

`validate()` throws `ValidationException`, or returns the values: ids as integers, `parent` as the record (the
lookup is shared by the parent rule, the slug rule and the caller), the status enum, the UTC date, and every field of
the type by name as its form value.

`App\Support\Content\EntryAuthors::query($type)`: the users who hold `<type>.create` directly or through a role, and
super admins; `options($type)` gives `id => name` by name.

`App\Policies\EntryPolicy` checks the type's own permission: `viewAny($user, $type)` and `view` → `view`,
`create($user, $type)` → `create`, `update`, `restore` and `preview` → `edit`, `delete` and `forceDelete` →
`delete`. Use `can('create', [Entry::class, 'pages'])` for the two that take a type key.

`App\Actions\Content\CreateEntry::handle($actor, $type, $locale, $data, $source = null)`: authorizes `create`;
refuses a language that is not on the site (`ContentLocales::has()`, a `locale` field error); with `$source` joins
its group, else makes a group. When the group already has a record in that language (trashed ones count, as in the
unique index), that record is returned unchanged; also when another request created it meanwhile: a slug that then
looks taken, or the unique index refusing the insert, ends in a look-up that returns the winner. Publishing or
scheduling (`status` = `published`) needs the type's `edit`: without it, it is a `status` field error. The author
defaults to the actor; only an actor with the type's `edit` chooses another one, and for anyone else the author input
is ignored and the actor is the author (so a creator can save a translation made with `copyFor()`, which copies the
source's author). A path held by a redirect row takes it over (the row goes).

`App\Actions\Content\UpdateEntry::handle($actor, $entry, $data)`: authorizes `update`; refuses a record in the trash
with `ProtectedActionException` (`content.entry.trashed`: it is restored first); the record's type and
language never change, whatever the data says. A changed slug or parent goes through `EntryPaths::move()`. A path
taken by another request between the check and the write is a `slug` field error.

Both validate the data themselves (`EntryInput`), so a tampered value is a field error with nothing stored, and write
in one transaction through `App\Actions\Content\EntryWriter`: the body through `BodySanitizer::clean()`;
`published_at` set to now when publishing without one; per-language field values (`toStorage()`) into
`entries.fields`, shared ones into `entry_groups.shared` (merged, so a value written from one translation reads the
same from every other); then the media usages: `image`, `body` (only ids of existing files) and `fields.<name>` on the
record, `shared.<name>` on the group (a rich text field's images only when the file exists). A new record with no files makes no usage query.

`Entry::copyFor($locale)` is the form data of a new translation: title, body, excerpt, SEO, image, author and every
field (shared ones carry the group's values), `parent_id` = the parent's translation in that language or null, an
empty slug, `draft` and no date. Nothing is stored.

`Entry` and `EntryGroup` implement `UsesMedia`. `Entry::mediaUsageLabel()` is "Page: <title>" (the type's label);
`mediaUsageUrl()` is the edit page for a viewer who may view the type (also for a trashed record, whose form opens
read-only); `forgetMedia()` clears `image`, removes the file's images from `body`
(`withoutMedia()`) and empties a `fields.<name>` value or takes the id out of a gallery. `EntryGroup` is named after
its record in the default content language ("Page: <title> (all languages)") and empties `shared.<name>` the same
way. Trashed records are reached too: `MediaUsage::usable()` loads with `withTrashed()`, so a file deleted while its
record is in the trash is gone from the record (image cleared, body without its images) when it is restored.

## Trash

Soft deletes are the trash. A trashed record keeps its path, which stays taken for slugs (`EntrySlug`, `suggest()`)
while it is there; `public()` leaves it out. No trashed record is ever left under a path whose record is gone: the
path rules only see exact matches, so such an orphan would collide with a later page at that prefix. Nothing live can
sit under a trashed record either (trashing takes the live descendants along, and a trashed record is never a valid
parent).

| Action | Permission | Meaning |
|---|---|---|
| `TrashEntry::handle($actor, $entry): int` | `delete` | trashes the record and its live descendants with one shared `deleted_at` (to the second), in one `UPDATE` whatever their number; returns how many (0 when the record already was in the trash) |
| `RestoreEntry::handle($actor, $entry): Entry` | `restore` (`edit`) | restores the record and the descendants carrying exactly its `deleted_at` (trashed with it); a child trashed earlier on its own keeps its own time and stays. Two trash actions within the same second share a timestamp, so they restore together. A record below the top level whose parent is still in the trash (or gone) becomes top level: the slug gets a suffix when that path is taken or reserved (`suggest()`), and `move(..., keepOldPaths: false)` carries its descendants' paths along without redirects; the parent restored later comes back without it. When another request takes the suggested path before the write (the unique index refuses it), the writes roll back to their savepoint and a fresh suggestion is tried once; a second refusal is a `ProtectedActionException` (`content.entry.restore_conflict`) and nothing is restored. A live record is returned unchanged |
| `DeleteEntry::handle($actor, $entry): void` | `forceDelete` (`delete`) | only a trashed record (`ProtectedActionException`, `content.entry.not_trashed`); removes it and every trashed record under its path (same type and language), whenever each was trashed. Only trashed rows are touched |
| `PruneEntryTrash::handle(): int` | none (decision 16) | deletes for good what was trashed more than `content.trash_days` days ago, with every trashed record under it (even one trashed after the cut-off), 200 records per chunk, a transaction per chunk |

`DeleteEntry::purge($records)` (trashed records loaded with `id`, `type`, `locale` and `path`) is the removal both
share, without authorization: it loads the records with every trashed record under their paths, then removes their
media usages, the rows (their redirects follow by foreign key), then every group left with no record (trashed ones
counted) with its usages. Six queries whatever the number of records. A group that still has a record in another language stays.

`content:prune-trash` prints "Removed N trashed record(s)." and runs `daily()` in `routes/console.php` next to
`media:prune-deleted`. The admin list prunes too, so no cron is required.

A content language that has records, trashed ones included, cannot be removed (`RemoveContentLocale`, see the
languages spec).

## Admin

Routes in `routes/admin.php` (prefix `admin`, `auth`, `verified`, `can:admin.access`), `{type}` constrained by
`ContentTypes::pattern()` (an unknown type is 404):

| Route | Name | Page |
|---|---|---|
| `admin/content/{type}` | `admin.content.index` | `pages::admin.content.index`, the list; authorizes `viewAny` |
| `admin/content/{type}/create` | `admin.content.create` | `pages::admin.content.create`, the form for a new record; authorizes `create`. Takes `locale` and `from` |
| `admin/content/{type}/{entry}/edit` | `admin.content.edit` | `pages::admin.content.edit`, the form of a saved record: a record of another type is 404, authorizes `view`; trashed records resolve (`withTrashed()`) and open read-only |

**Sidebar.** The "Content" group lists every registered type the actor may view (`viewAny`, its plural label and icon),
then Media; the group shows when any of them or `media.view` holds.

**List** (`pages::admin.content.index`, a plain full-page component, no islands):

- Header: the type's plural label and description; "Create page" (`create`) links to `admin.content.create` with the
  list's language.
- Tabs All, Published, Drafts, Scheduled, Trash with counts. Published is `public()`; Drafts is `draft` or a published
  record without a date; Scheduled is published with a future date. The counts are of the type and language, whatever
  the search and the filter.
- Filters: search (`search_index`, title and slug, case-insensitive with Cyrillic); the list's language (every
  language added to the site, a switched-off one marked "off"); "Missing: <language>" (records whose group has no live
  record in that language, trashed translations count as missing). Both language selects and the Translations column
  are absent on a site with one language.
- State in the query string: `tab` (default `all`), `q`, `locale`, `missing`, `sort`, `dir`, `page`, so a filtered list
  can be bookmarked and Back works. The language is remembered in the session (`content.list_locale`) and defaults to
  the default content language; a value that is not a language of the site falls back to the default. An unknown tab is
  `all`.
- Sort: title and date (`coalesce(published_at, created_at)`), toggled by the column headers. A hierarchical type
  defaults to tree order and a third click on a header (or the "Tree order" button) goes back to it. Tree order sorts
  by `path` with `/` read as a space, so `about/team` comes right after `about` and before `about-us`; rows are
  indented by `depth()` only on the All tab with no search and no "Missing" filter (a status tab or a filter can
  hide a row's parent, and a child would be indented under nothing).
- Row: thumbnail (`thumbUrl()` of the image, else a neutral block; fixed 40 px), the title linking to the edit page,
  the address (`url(false)`), a Draft or Scheduled badge, the translations bar, author, date, and the "⋮" menu. On a
  phone the translations, author and date columns are hidden.
- Translations bar: green = published, amber = draft or scheduled, `n/total` where total is every language added to
  the site (switched-off ones included). Hovering (or focusing) it opens `partials/admin/content/translations-grid`.
- Menu: Edit (`edit`), View on site (public records), Add translation (`create`; a submenu of the languages the group
  lacks, each to `admin.content.create` with `from` and `locale`), Move to trash (`delete`).
- Trash tab: a flat list by `deleted_at`, newest first, with "Removed on <date>" (`deleted_at` + `trash_days`). Menu:
  Restore (`edit`, `RestoreEntry`) and Delete for good (`delete`, `DeleteEntry`).
- Confirmations (`<x-confirm-modal>`): moving to the trash names the number of subpages going with it
  (`descendantsCount()`); deleting for good is always confirmed and names the number of trashed records under it.
  Results are toasts; a `ProtectedActionException` (a restore conflict) is a danger toast.
- Empty states: no records at all invites creating the first; a search or filter with no match offers "Clear filters";
  an empty trash says how long records stay there; an empty tab says so.
- Opening the list prunes the trash (`PruneEntryTrash`) after the response at most once an hour per type (cache key
  `content.prune_trash.<type>`), so no cron is required.

`App\Support\Content\EntryListQuery::for($type, $locale)` builds the list: `tab()`, `search()`, `missingIn()` (a `not
exists` on the group), `sort('title'|'date'|'tree', 'asc'|'desc')`, `isTree()`, `query()`, `paginate($perPage)` and
`counts()` (one aggregate query). It selects only the columns a row shows and eager loads `image`, `author` (`id`,
`name`) and `groupEntries` (`id`, `entry_group_id`, `locale`, `title`, `status`, `published_at`), so the page's query
count does not depend on the number of rows (asserted with 5 and 20 rows). Permissions are checked once per render,
not per row.

**Translations grid** (`partials/admin/content/translations-grid.blade.php`, reused by the form): receives `$entry`,
`$languages` (`ContentLocales::all()`), `$groupEntries` (the loaded relation) and optionally `$canCreate`,
`$trashedEntries` (the group's trashed records keyed by language; only the form passes them) and `$fromId` (what the
create links copy; `$entry->id` by default). Four codes per row: the record's own language filled dark, an existing
translation green (published) or amber (draft or scheduled) linking to its edit page, a trashed one (form only) muted,
struck through, with a trash mark, "in the trash", linking to its read-only edit page, a missing one dashed with "+"
linking to the create page with `from` and `locale` (a plain mark without `create`); a switched-off language is muted
with an "off" mark. Each cell carries `data-state` (`this`, `published`, `draft`, `trashed`, `missing`). A legend with
the counts follows (the trash only when there is any). It makes no query. In the list a trashed translation counts as
missing, as for `CreateEntry`: its create link redirects to the trashed record.

**Form** (`pages::admin.content.create` and `pages::admin.content.edit`, both on `App\Livewire\Admin\EntryFormPage`
(decision 32), the markup in `partials/admin/content/entry-form.blade.php`, the values in
`App\Livewire\Forms\EntryForm`; plain full-page components, no islands, `wire:model` without `.live` everywhere):

- **New record.** `locale` from the query must be a language of the site (`ContentLocales::has()`), else 404; without it
  the list's remembered language, else the default. With `from` (a live record of the type, else 404): when the group
  already has that language, trashed or not, the page redirects to that record's edit page (a trashed one opens
  read-only with Restore); otherwise the form is filled from `copyFor()` and the slug suggested from the copied title
  under the mapped parent. The copied author is kept only while they can still be one (`EntryAuthors`); otherwise the
author is the actor, so the select shows a valid choice. Nothing is stored before Save. Without `from` the author is
the actor. If the source went to the trash or was deleted after the form opened, Save refuses with a `form.source`
error instead of creating a record outside the group. Save calls
  `CreateEntry` and redirects to the edit page with a toast ("The record is created.", or "already exists" when the
  action returned an existing translation).
- **Saved record.** `entryId` is locked; the record is loaded with its group (shared field values). Save calls
  `UpdateEntry` and redirects back with a toast. A record in the trash is read-only and refuses Save (403); trashing
  a record that is already in the trash does nothing (no toast, no redirect). The record the route bound serves the
  first render, so it is loaded once.
- **Header**: a back link to the list (in the record's language), the record's title (or `newLabel()`) with a
  Draft/Scheduled/"In the trash" badge, "View on site" (a public record) or "Preview" (a draft or scheduled one),
  both opening `Entry::url()` in a new tab and only for a saved record outside the trash, and Save. Page title:
  `content.form.page_title` (":type: :title") through `->title()`.
- **Left column**: a card with the title, the address (the prefix as text: the site address without the scheme, the
  language prefix when not the default, the type's base, the parent's path; the slug is the input), the body
  (`<x-admin.rich-text>`, its images given as `editorImages`, id => URL with one query for the body and the rich text
  fields) and the excerpt; a "Details" card with `localFields()` when the type has any; the "Search engines" card with a
  live preview (address, title falling back to the title and the site name, description falling back to the excerpt)
  and the SEO title and description.
- **Right column**: "Publication" (status Draft/Published, date and time as `datetime-local` in `admin.timezone` with
  the timezone named under it; a hint when Published with a future date, and when Published without a date); a user
  without the type's `edit` sees the status fixed to Draft with a line that an editor publishes it. "Language and
  translations" (hidden on a site with one language): the record's language, `n of total`, a progress bar and the grid
  (with trashed translations); a new record without a source says translations come after saving. A `locale` error
  (the language removed before Save) and a `source` error show in a callout at the top of the form, visible on a
  one-language site too. "For all languages" with `sharedFields()` and the line that a change
  applies to every translation (absent when the type has none). "Image" (the media picker in field mode,
  `accept="image"`). "Attributes": the parent (hierarchical types; a native select in tree order, indented by depth,
  without the record and its descendants, plus "No parent"; one query; a trashed record shows only its actual parent,
  trashed or not, so its read-only prefix is its stored path) and the author (a select of
  `EntryAuthors::options()` plus the current author; only the name for a user without `edit`). "Move to trash"
  (`delete`): `<x-confirm-modal>` naming the subpages going with it, then `TrashEntry`, a toast and the list.
- **Field partials**: `partials/admin/content/fields/<Field::view()>.blade.php` (`text`, `textarea`, `rich-text`,
  `number`, `date`, `toggle`, `select`, `media-file`, `gallery`) get `$field`, `$model` (`form.fields.<name>`),
  `$readOnly`, `$images` and `$locale`; the label is `labelText()`, errors sit under each field. `media-file` and
  `gallery` use the picker in field mode (a read-only form lists the files instead, from one query).
- **Read-only** (`view` only, or a trashed record): every control disabled, no Save, no trash, no picker. A trashed
  record shows "This record is in the trash" with the date it will be removed and Restore (`edit`; `RestoreEntry`,
  then the edit page with a toast); other read-only forms say they can be viewed only.
- **Validation**: the actions validate (`EntryInput`); their errors are put on `form.<key>` and the browser event
  `entry-form-invalid` scrolls the first one into view. The editor keeps its content.
- **Browser side** (Alpine `entryForm`, `resources/js/entry-form.js`): while a new record's slug has not been typed by
  hand it follows the title: 400 ms after the title (or the parent) changes, `suggestSlug()` (`#[Renderless]`,
  `EntryPaths::suggest()` for the form's title, parent and language, the record itself ignored; it only returns the
  slug and sets no property, so a response never overwrites a slug typed meanwhile) fills it through a guarded
  `$wire.set`; typing in the
  slug stops that, emptying it resumes it; a saved record's slug never follows. The prefix follows the parent select at
  once (the parents' paths are in the page); the server renders the full prefix, parent path included. The SEO preview, the hints and the "Unsaved changes" mark update without a
  request. Ctrl/Cmd+S saves (not while a dialog, such as a media picker, is open); a sticky bar at the bottom repeats Save. Leave-page warning (the settings page's
  pattern): the form's values are compared with those it opened with, rich text excluded, plus the editors' change
  signal (`rich-text:changed`); it stays quiet once `finished` is set by a successful save or trash.
- **Cost**: opening the edit form is a fixed number of queries whatever the number of translations or of pages
  offered as parent (asserted): the record with its group, one for the group's other records (trashed ones included),
  one for the parents, one for the authors, one for the editor's images when the texts have any; typing in the title,
  excerpt or SEO fields makes no request except the debounced slug suggestion. One detached media picker is mounted
  for the editors.

## Public side

Plain controller and Blade, no Livewire (the user's standing rule: public pages never use Livewire).

**Route.** `routes/web.php`, inside `Route::localized()`: `Route::get('{path}', ContentController::class)->where('path',
'.*')->fallback()->name('content')`, so `content` and `localized.content` (`{locale}/{path}`). Both copies are fallbacks:
every explicit route (admin, Fortify, settings, media, `up`, Livewire, the home page) is tried first, and the prefixed
copy still precedes the plain one, so `/de/about` is the German page `about`, never a default-language page `de/about`
(a first segment equal to a catalogue code is always a language). GET and HEAD only; a POST to an unknown address
therefore answers 405 instead of 404, as with Laravel's own `Route::fallback()`. The routes serialize and the compiled
(cached) collection keeps both content routes last. `ReservedSlugs` skips them (they carry `SetContentLocale`, and their
first segment is a parameter).

**Resolution** (`App\Support\Content\ContentRouter::resolve($locale, $path, $viewer): ?ContentMatch`), in the request's
language (`ContentLocales::current()`, set by `SetContentLocale`, which also removes `{locale}`):

1. A first segment equal to a registered type's `base($locale)`: the rest is that type's record path. A bare base is the
   type's list, which a later part builds: null (404) for now, with no query.
2. Otherwise a record of the types without a base (`pages`) by the full path (one indexed lookup on `type, locale,
   path` for any depth; with several such types the earliest registered wins).
3. On a miss, one lookup in `entry_redirects` (`type, locale, path`, joined to the live record by a subquery): found →
   `ContentMatch::redirect($record->url())` when that record is public or previewable by the viewer, else null.
4. Null.

A path with a capital letter is no record's (slugs are lowercase) and is refused before any query, so SQLite and MySQL
(whose comparison ignores case) agree. A trailing slash is removed by the router as before. No other normalising and no
redirect for those.

`ContentMatch` (`entry`, `preview`, `redirectTo`): `ContentMatch::entry($entry, $preview)` or `::redirect($url)`.

**Visibility.** A public record (`isPublic()`) is shown to everyone. A draft or scheduled record resolves only for a
signed-in user who may edit its type (`EntryPolicy::preview`), with `preview: true`; everyone else gets the same 404 as
for a missing page (no redirect lookup is made for it either). A trashed record is 404 for everyone.

**Controller** (`App\Http\Controllers\ContentController`): null → 404; a redirect → 301 with `Cache-Control: no-cache`
(the old path may be taken by a new record later, so a browser must not keep it for good); else the view `site.entry`
with the record, the body through `BodyRenderer::render()`, `preview` and `editUrl` (the admin edit page, only for a
viewer with the type's `edit`). A preview answers `Cache-Control: no-store`.

**Alternates.** The controller passes `ContentLocales::useAlternates()` the addresses of the group's records that are
public and in an active language (the record itself included when public), in the site's order of languages. The
`hreflang` partial prints them plus `x-default` (the default language's address, left out when the default language has
no public translation), and nothing when there is only one. The switcher lists every active language.

**Templates** (a folder of their own so a theme can replace them):

- `layouts/site.blade.php`: `<html lang dir>` from the content language, `<title>` (`seo_title`, else the title, then
  " - " and the site name), `<meta name="robots" content="noindex">` in preview, the favicons, the site's CSS only
  (`@vite('resources/css/app.css')`), the color scheme (`partials.color-scheme`), a small inline script that sets the
  `dark` class from the stored appearance or the system (no Flux, Livewire or admin JavaScript is loaded), `hreflang`,
  and a header with the site logo (when one is chosen) or the site name linking to the home page in that language, and
  the switcher. No description, canonical or Open Graph tags yet.
- `partials/site/language-switcher.blade.php`: a `<details>` with plain links, no JavaScript; each active language links
  to the translation when it is among the alternates, else to that language's home (`ContentLocales::route('home', [],
  $code)`); the current language links to the current page and carries `aria-current`. Absent with one language.
- `site/entry.blade.php`: the preview strip ("Draft", or "Scheduled for <date and time in `admin.timezone`>", a note that
  visitors do not see the page yet, and an "Edit" link), the title as `<h1>`, a small "Edit" link for an editor on a
  published page (guests see neither), the image (current URL, `width`/`height`, the file's alt in the page's
  language), and the body in `.rich-text-content` (the editor's typography: headings, lists, quotes, code, rules, images
  `max-width: 100%; height: auto`), in a column of `max-w-3xl`. The type's own fields are not printed. Strings:
  `content.public.*`.

**Cost per page view** (asserted: at most 5 queries more than the home page in the same setup; the home page makes none
in the tests):

1. `select * from entries where locale = ? and path = ? and type in (?) and deleted_at is null`
2. `select id, entry_group_id, type, locale, path from entries where entry_group_id in (?) and status = 'published' and
   published_at <= ? and locale in (<active>) and deleted_at is null` (the public translations)
3. `select * from media where id in (?)`, the record's image, only when it has one
4. `select * from media where id in (…)`, the body's images, only when the body has any

Plus the site logo (one query, only when a custom logo is chosen) and, for a signed-in viewer, what the session and
permission checks cost. A miss is two queries (1 and the redirect lookup), a redirect the same two, a draft for a guest
one; a bare base or a path with a capital letter none.

## Tests

`tests/Feature/Content/PublicContentTest.php`: a page in the default language and its translation under the prefix, a
nested path, a record under a type's base and the bare base, uppercase and trailing slash, the image, the SEO title, the
rendered body, no Livewire or script files; preview (draft, scheduled and trashed are 404 for a guest and a user without
`pages.edit`; the strip, `noindex`, `no-store` and the edit link for an editor; the scheduled date in the site's
timezone; the Edit link on a published page); `hreflang` (public translations in active languages, `x-default`, none for
one, no `x-default` without the default language) and the switcher; `/de/about`; languages off or not added; no leak of
alternates into the next request; redirects in both languages, to a draft only for an editor, after a `move()`; every
other route unchanged (by the router's match), reserved slugs, `url()` from the route without a query, the compiled route
cache; the query budget and the cost of a miss and a redirect; the list's "View on site" and the form's "Preview".
`tests/Feature/Admin/Content/EntryIndexTest.php`: access (guest, 403, unknown type), the sidebar item and group, tabs
and counts, Cyrillic search, tree order and indent for three levels (with an `about-us` sibling), title sort and back to
the tree, `missing`, the list's language (session, fallback), the translations bar and its create links, the one-language
site, the Create button, trash/restore/delete for good with their permissions and confirmation texts, the row menu, empty
states, the query string, the same query count for 5 and 20 rows, the hourly prune, the indent only on the full tree, and
that the create and edit pages authorize.
`tests/Feature/Admin/Content/EntryFormTest.php`: creating through the form (stored, redirect, toast), the language from the
query (404 for one not on the site), the page title, `suggestSlug()` (free, suffixed, under a parent), editing title,
parent and slug, errors on their fields with the body kept, the parent options (no own subtree, no other language),
a tampered parent and a language removed before saving, a translation from `copyFor()` saved into the group, the
redirect to an existing or trashed translation, view-only (read-only, `save` and `confirmTrash` refused), a creator
without `edit` (status locked, author as text, publishing refused), trash with its confirmation, a trashed record
(read-only, removal date, Restore with and without `edit`), another type's record 404, View on site / Preview, a type
with fields (`ProductsType`: one control per field, local and shared values stored in place, a field error), no field
cards for `pages`, the language card states and links (trashed included) and its absence on a one-language site, the
editor's images and the detached picker, the same query count for few and many translations and pages, and the media
library's usage link to the edit page (also for a trashed record).
`tests/Feature/Content/ContentRegistryTest.php`: the registry and `pattern()`, `pages`, the permissions after a
sync and for the editor, every field kind's round trip and rules, shared and local fields, media ids.
`tests/Feature/Models/EntryTest.php`: public/scheduled/trashed visibility, the unique indexes, translations, the
tree, the null-on-delete keys, `url()`, `fieldValue()` and the search index. The
test-only `Tests\Fixtures\ProductsType` (`tests/fixtures/ProductsType.php`) has one field of every kind with
`price` shared; tests register it themselves.
`tests/Feature/Admin/Content/RichTextComponentTest.php`: the editor component's binding config (no `wire:model` on the page), the image button only with `media.view`, read-only without a toolbar. The editor itself runs only in a browser and has no automated test; `isContentEdit()` and `normalizeHref()` were checked once with a Node script against the installed Tiptap (the extension manager's plugins on a headless editor), not kept in the suite. The form's browser side (slug following, SEO preview, leave-page warning, Ctrl/Cmd+S, scrolling to an error) has no automated test either.
`tests/Feature/Content/BodyTest.php`: hostile inputs as a dataset with the exact output, allowed markup kept, Cyrillic round trip, long body, `mediaIds()`, `withoutMedia()`, rendering (one query, versioned URL, dropped images, alt fallback, no query without images).
`tests/Feature/Content/EntryPathsTest.php`: suggested slugs, the slug rule, `move()` (descendants, redirects, the same
query count for 2 and 50 descendants, redirect rows that already exist).
`tests/Feature/Actions/Content/EntryTrashTest.php`: trashing a tree (one instant, out of `public()`, the path still
taken, `descendantsCount()`), the same query count for 2 and 30 descendants, restoring exactly what was trashed
together, a child trashed before its parent restored to the top level while the parent is in the trash (suffix for a
taken path and for a reserved name) and the parent restored later without it, the retry when the new path is taken
meanwhile (and the refusal when it is taken twice), deleting for good (every trashed record under the page, the count
equal to `descendantsCount()`, redirects, usages, an emptied group, a group kept for another language), no live record
under a trashed one, the address free again after a delete for good (a new `about` with a child `team`), a live record
refused, permissions, pruning (31 and 29 days, a child trashed 40 days ago with or without its parent, a record under
a pruned one trashed after the cut-off), the command and its schedule, and a file deleted while its record is in the
trash. `tests/Feature/Models/MediaUsageTest.php` checks that `forgetAll()` reaches
a trashed record and a `Setting`; `tests/Feature/Localization/ContentLanguagesTest.php` the language removal rule.
`tests/Feature/Actions/Content/SaveEntryTest.php`: create (group, path, author, cleaned body, usages, a redirect row
taken over), update (slug and parent moves, type and language kept), translate (group, `copyFor()`, parent mapping),
a creator without `edit` (publishing refused, translating from `copyFor()` makes them the author), a descendant
path pushed past 255 characters (slug and parent), a trashed record not updated, the existing translation returned
(also trashed, also after losing the race: a query listener inserts the competing
record right after the action's own look-up, once failing validation and once the unique index), publishing dates
and the timezone, shared fields, tampered values as a dataset (nothing stored), permissions, the policy,
`EntryAuthors`, and usages and `forgetMedia()` for every kind (a test type with a file field, a gallery and a shared
gallery).

## Not included yet

Lists of a type under its base, head tags beyond the title (description, canonical, Open Graph), `sitemap.xml` and the
home page setting (a later part).

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
| `base($locale)` | first address segment of the type in a language, or null for the root; must be one slug in the slug format (`assertValidBase()`) |
| `fields()` | the extra fields (none by default); `field($name)`, `sharedFields()`, `localFields()` |
| `description()` | the line under the admin list's heading, from `content.types.<key>.description` |
| `createLabel()` | the list's "Create" button ("Create page"), from `content.types.<key>.create` |
| `newLabel()` | the heading of a new record's form ("New page"), from `content.types.<key>.new`, else `createLabel()` |
| `perPage()` | admin list page size, 20 by default |
| `permission($action)` | `"{key}.{action}"` |
| `assertValidBase($locale)` | throws `InvalidArgumentException` when `base($locale)` is neither null nor one segment matching `EntrySlug::FORMAT` (`blog/news`, `/blog`, `Blog` and `''` are refused): `Entry::url()` would build an address the content route never matches |

`App\Support\Content\ContentTypes` (singleton) holds the registered types: `register()`, `all()`, `find()`,
`get()` (throws on an unknown key) and `pattern()`, an alternation of the keys for route constraints (it
matches nothing while no type is registered). Keys follow the permission segment format. `register()` refuses a key
that is already registered and checks the type's base in every language of the catalogue (`assertValidBase()`, about
130 `base()` calls once per request, when the registry is built), both with `InvalidArgumentException`. It also
registers the type's `view`, `create`, `edit` and `delete` (`ContentTypes::ACTIONS`) with `PermissionRegistry`, so
a type registered at any time (a module's, or a test's) is seen by `permissions:sync` and the role matrix.

A module registers its type from its provider's `register()` (for example
`$this->app->afterResolving(ContentTypes::class, fn (ContentTypes $types) => $types->register(new PostsType))`), not
from `boot()`: the admin routes' `{type}` pattern (`pattern()`) is built when the routes load, which can happen before
another provider boots, and a type registered after that has no admin pages (and the route cache keeps the pattern it
was built with).

`App\Providers\ContentServiceProvider` binds the singleton and registers the classes listed in `config/content.php`
→ `types`, in that order (each made by the container): `PagesType` (key `pages`, hierarchical, no base, no fields) in
the kit. The list holds class names only, so the config cache keeps it and a request pays nothing for it; a type's
definition stays a class, because fields are objects and `base()` may need logic. Its `boot()` builds the registry,
so the kit's types have registered their permissions before anything reads them. A config cache built before the
list existed has no `types` key; the provider then registers `PagesType` alone instead of nothing. Publishing and
restoring need `edit`; trashing and deleting for good need `delete`. The `editor` role gets the four `pages.*`
permissions by default; `admin` gets them through `*`.

### Adding a type

`php artisan make:content-type Posts` (`App\Console\Commands\MakeContentType` over
`App\Support\Content\ContentTypeGenerator`) writes `app/Support/Content/Types/PostsType.php`, adds the class to
`config/content.php` → `types` (with its `use` line), and adds the type's lines to every language's `content.php`
(`types.<key>`: `label`, `plural`, `description`, `new`, `create`) and `permissions.php` (`groups.<key>`,
`items.<key>.*`). The lines are English in every language, built from the name, to be translated by the developer.

| Input | Meaning |
|---|---|
| `name` | plural name (`Posts`, `CaseStudies`; a trailing `Type` is dropped); the class is `<Name>Type` |
| `--key=` | the type's key; the name in kebab case by default (`case-studies`) |
| `--base=` | first segment of the public address in every language; the key by default |
| `--tree` | hierarchical, like pages; flat by default |
| `--icon=` | the menu icon, `document-text` by default |

The key and the labels come from the words of the name, split where a capital follows a lowercase letter or a
digit, so an acronym stays one word (`FAQs` → `faqs`); the default base is the key with hyphens for underscores.

It refuses, changing nothing: a cached config (the registered types may not be the file's; `config:clear` first), a
name that is no class name, a key outside the key format, already registered or already a permission group (`users`
would share the Users page's permissions), an existing class file, a base that is not one slug, is reserved
(`ReservedSlugs`), is the base of a registered type in some language or is the address of an existing top-level
record of a base-less type (trashed included; the router resolves a base first, so the record would stop opening),
and an icon the admin does not have (a Flux icon, its own or the project's in `resources/views/flux/icon`; the sidebar renders it on every admin page). The config
and language files are edited as text by the shape Pint gives them (a top-level `'section' => [` closed by `],`,
the `use` line placed in sorted order); a file of another shape, one whose imports already hold the class's short
name, or one that would no longer load with the new lines, is left as it was and its lines are printed to add by
hand. A file that already holds the type's lines (left from an earlier run or added by hand) is not touched. Any
other failure midway (a file that cannot be written) puts every changed file back and removes the class file. Afterwards: translate the
lines, run `permissions:sync`, give the permissions to roles, and rebuild the config and route caches if they are
cached. The command only writes files; it runs no migration and writes nothing to the database.

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
(`type`, `locale`, `path`); (`type`, `locale`, `deleted_at`, `status`, `published_at`) is a plain index (the list's counters and page count
read it alone), and so is `deleted_at` (the trash prune). `published_at` is a `dateTime`, not a `timestamp`, whose
range on MySQL is 1970 to 2038. `parent_id` has no explicit
index: MySQL creates one for its foreign key; on SQLite the column has none. The
path index is 307 characters wide, which fits MySQL's limit on utf8mb4 (checked on MySQL 8). Deleting a user
nulls `author_id`, deleting a media file nulls `image_id`, deleting a parent nulls `parent_id`, deleting an entry
for good removes its redirects.

`App\Models\EntryGroup`: casts `shared` to an array; `entries()`; `takenLocales($groupId)` (the languages the group
holds a record in, trashed ones included) and `recordIn($groupId, $locale)` (that record, trashed or not) are the one
answer to "is this language taken" for `CreateEntry`, `UpdateEntry` and the create page. `App\Models\EntryRedirect`: `entry()`.

`App\Models\Entry` (soft deletes are the trash; `HasSearchIndex` from title and slug):

| Member | Meaning |
|---|---|
| `group()`, `parent()`, `children()`, `author()`, `image()` | relations |
| `groupEntries()` | relation: every record of the group, this one included, trashed left out; eager load it in lists |
| `translations()` | method, not a relation: the other records of the group, taken from the loaded `groupEntries` when there is one (no query per record) |
| `ofType($type)`, `inLocale($locale)` | scopes |
| `inState(EntryState)` | scope: the SQL of `state()`, the one definition the list's tabs and `public()` use |
| `under($type, $locale, $path)` | scope: the records under a path (same type and language, path starting with it and `/`); `descendants()` and the purge use it |
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
| `relocate($entry, $locale, $path)` | for the caller's transaction, when a record without descendants takes another language: stores the old (type, language, path) in `entry_redirects` pointing at the record (upsert), deletes a redirect row holding the new language and path, and sets the record's `locale` and `path` without saving. `move()` is not used for it: it matches descendants by the path prefix, which in the new language would be unrelated records |
| `isReserved($type, $locale, $parent, $slug)` | the one answer to "reserved at the top of the address": no parent, a type with no base, and the slug is in `ReservedSlugs` or is the base of any registered type in that language. Used by both `suggest()` and `EntrySlug` |

`App\Rules\EntrySlug($type, $locale, $parent, $ignore)` checks, in this order: the format
`^[a-z0-9]+(-[a-z0-9]+)*$`; not reserved (only for a record at the top of the address, that is without a parent
and in a type with no base: `ReservedSlugs`, or the base of any registered type in that language); the full path
at most 255 characters; not taken by another record of the type and language, trashed ones included, the record
being edited excepted (one query; when the holder is in the trash the message says so, `content.slug.taken_trashed`:
restore it or delete it for good). Messages are `content.slug.*`. An empty value is left to `required`. The format is
anchored with `\A` and `\z` (`EntrySlug::FORMAT`), so `"about\n"` is refused.

## Body

A record's body is HTML from the rich text editor. It is sanitized once on save and printed unescaped, so the
sanitizer is the security boundary. Package: `symfony/html-sanitizer` (parsed with PHP's `Dom\HTMLDocument`).

`App\Support\Content\BodySanitizer`:

| Method | Meaning |
|---|---|
| `clean($html)` | allowlist: `p`, `h2`-`h4`, `strong`, `em`, `u`, `s`, `ul`, `ol`, `li`, `blockquote`, `br`, `hr`, `code`, `pre`, `a` (`href`, `target`) and `img` (`data-media-id`, `alt`). Links: `http`, `https`, `mailto`, `tel` and relative addresses only; the sanitizer checks the scheme after decoding entities and ignoring case and whitespace. Any other attribute (`style`, `class`, `on*`, `src`) is dropped. `data-media-id` must be a positive whole number, else it is dropped, and an `img` left without one is removed. Unknown elements keep their text but lose their tags; `script`, `iframe`, `object`, `embed`, `svg`, `math`, `form`, `template`, `noscript`, `textarea`, `select`, `style`, `title` and the other head elements are removed with their contents. A link keeps `target` only as `_blank` and then always gets `rel="noopener noreferrer"`; a link without a target has no `rel`. A `pre` keeps its leading newline across repeated saves. `xmp`, `noembed`, `noframes` and `plaintext` are removed with their contents too. The result is null when it has no text (whitespace and non-breaking spaces ignored), no `img` and no `hr`, so the editor's empty `<p></p>` is an empty body. The input is cut to `BodySanitizer::MAX_LENGTH` (4 000 000 bytes, before any parsing; the form must validate against it). The package's own length limit is off (`withMaxInputLength(-1)`): the pre-pass's output may grow past the cut (each `&` comes back as `&amp;`), and the package would cut that silently; a body under the form's limit is never shortened |
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
  only. Body typography is the `.rich-text-content` class in `resources/css/rich-text-content.css`, imported by both
  `app.css` (the editor) and `site.css` (the public page), so the rules exist once in the source; the editing box
  (padding, minimum height, a smaller size) is `.rich-text-content.ProseMirror`.

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

`App\Policies\EntryPolicy` checks the type's own permission: `viewAny($user, $type)` and `view` → any of `view`,
`edit` and `create` (as decision 31 does for roles, so `edit` or `create` without `view` is not a dead end),
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
source's author). A translation (`$source` given) by an actor without the type's `edit` leaves the group's shared values
alone: the shared fields of the data are neither validated nor written (`EntryInput::validate(..., withSharedFields:
false)`), and the group and its `shared.<name>` usages are not touched, because those values belong to the records
in the other languages, published ones included. A new record (no source) has a group of its own, and a creator sets
its shared values. A path held by a redirect row takes it over (the row goes).

`App\Actions\Content\UpdateEntry::handle($actor, $entry, $data, $locale = null)`: authorizes `update`; refuses a record
in the trash with `ProtectedActionException` (`content.entry.trashed`: it is restored first); the record's type never
changes, whatever the data says. A changed slug or parent goes through `EntryPaths::move()`. A path taken by another
request between the check and the write is a `slug` field error.

**Changing the language.** `$locale` other than the record's own moves the record to that language in the same save
(decision 63). It is a `locale` field error when the language is not on the site (`content.entry.locale_invalid`), the
record's group already has a record in it, trashed ones included (`locale_taken`), or the record has anything under
it, trashed ones included (`locale_has_children`: those pages would stay in the old language). The data is validated
in the new language (the parent must be a record of that language, the slug free there), and
`EntryPaths::relocate()` keeps the old address as a redirect: `ContentRouter` redirects to `Entry::url()`, so the old
address leads to the new language's one.

`App\Actions\Content\LinkTranslations::handle($actor, $entry, $other)`: makes the existing record `$other` the
translation of `$entry` in its language. Authorizes `update` on both; refuses (`ProtectedActionException`) records of
different types, of one group already or in the trash (`content.link.invalid`), and a language the entry's group
already has, trashed ones included (`content.link.overlap`; checked again under a lock on both groups' rows). Only
`$other` moves: it leaves its own group, whose other records stay linked to each other, and takes the shared values
of the group it joins; a group left empty is deleted with its usages. Written past the model, so `updated_at` stays:
linking is not an edit.

`App\Actions\Content\UnlinkTranslation::handle($actor, $entry)`: takes the record out of its group into a new one
with a copy of the shared values and their usages (`EntryWriter::syncSharedUsages()`); the others stay together.
Authorizes `update`; refuses a record in the trash (`content.entry.trashed`) and one with no other record in its
group, trashed ones counted (`content.link.alone`).

Both validate the data themselves (`EntryInput`), so a tampered value is a field error with nothing stored, and write
in one transaction through `App\Actions\Content\EntryWriter`: the body through `BodySanitizer::clean()`;
`published_at` set to now when publishing without one; per-language field values (`toStorage()`) into
`entries.fields`, shared ones into `entry_groups.shared` (merged, so a value written from one translation reads the
same from every other; only the shared fields the validated input carries, none for a creator's translation); then
the media usages: `image`, `body` (only ids of existing files) and `fields.<name>` on the
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
| `EmptyEntryTrash::handle($actor, $type, $locales): int` | the type's `delete` | deletes for good every trashed record of the type in those languages, 200 per chunk, a transaction per chunk (`purge()`); the list's "Empty the trash" |
| `PruneEntryTrash::handle(): int` | none (decision 16) | deletes for good what was trashed more than `content.trash_days` days ago, with every trashed record under it (even one trashed after the cut-off), 200 records per chunk, a transaction per chunk |

`DeleteEntry::purge($records)` (trashed records loaded with `id`, `type`, `locale` and `path`) is the removal both
share, without authorization: it loads the records with every trashed record under their paths with
`lockForUpdate()`, then removes the media usages of those still in the trash (a subquery), the rows still in the
trash (`onlyTrashed()`; their redirects follow by foreign key), then every group left with no record (trashed ones
counted) with its usages. A record another request restored meanwhile is therefore left alive with its usages and
redirects (on MySQL the lock makes such a restore wait; SQLite has no row locks, and the trash filter covers a restore
until the transaction's first write, after which SQLite lets no other writer in). Six queries whatever the number of
records. A group that still has a record in another language stays.

`content:prune-trash` prints "Removed N trashed record(s)." and runs `daily()` in `routes/console.php` next to
`media:prune-deleted`. The admin list prunes too, so no cron is required.

A content language that has records, trashed ones included, cannot be removed (`RemoveContentLocale`, see the
languages spec).

## Admin

Routes in `routes/admin.php` (prefix `admin`, `auth`, `verified`, `can:admin.access`), `{type}` constrained by
`ContentTypes::pattern()` (an unknown type is 404):

| Route | Name | Page |
|---|---|---|
| `admin/content/{type}` | `admin.content.index` | `pages::admin.content.index`, the list; authorizes `viewAny` (`view`, `edit` or `create`) |
| `admin/content/{type}/create` | `admin.content.create` | `pages::admin.content.create`, the form for a new record; authorizes `create`, and `view` of the `from` record. Takes `locale` and `from` |
| `admin/content/{type}/{entry}/edit` | `admin.content.edit` | `pages::admin.content.edit`, the form of a saved record: a record of another type is 404, authorizes `view`; trashed records resolve (`withTrashed()`) and open read-only |

**Sidebar.** The "Content" group lists every registered type the actor may view (`viewAny`: `view`, `edit` or `create`; its plural label and icon),
then Media; the group shows when any of them or `media.view` holds.

**List** (`pages::admin.content.index`, a plain full-page component, no islands; its logic is the class
`App\Livewire\Admin\EntryListPage`, which the page extends, so PHPStan analyses it, decision 32):

- Header: the type's plural label and description; "Create page" (`create`) links to `admin.content.create` (no
  `locale`: the new form takes the list's remembered language, else the default).
- Tabs All, Published, Drafts, Scheduled, Trash with counts. Published is `public()`; Drafts is `draft` or a published
  record without a date; Scheduled is published with a future date. The counts are of the type in the list's languages
  (the chosen one, or all of the site's), whatever the search and the filter.
- Filters: search (`search_index`, title and slug, case-insensitive with Cyrillic; the term is cut at 200 characters,
  `SEARCH_MAX`, in `mount()` and `updatedSearch()`, and the input has `maxlength="200"`); the list's language ("All languages", the
  default, then every language added to the site, a switched-off one marked "off"); "Missing: <language>" (records whose group has no live
  record in that language, trashed translations count as missing). Both language selects and the row's language codes
  are absent on a site with one language.
- State in the query string: `tab` (default `all`), `q`, `locale`, `missing`, `sort`, `dir`, `page`, so a filtered list
  can be bookmarked and Back works. The list shows every language of the site by default (`locale` empty; the user's
  request, decision 64); a chosen language is remembered in the session (`content.list_locale`, empty again after
  choosing "All languages"), and a value that is not a language of the site shows them all. With every language
  listed the rows are groups of translations, one per group (the user's choice, decision 65): the group's record in
  the first of the site's languages among those the tab and the search match (so a draft translation of a published
  page is found on the Drafts tab, and a search finds a group by any of its titles), and the tab counts are of groups
  with a record in that state (`EntryListQuery::isGrouped()`, a correlated `not exists` on the `entry_group_id`,
  `locale` index; the Trash tab always lists and counts records). A chosen language lists that language's records. Records in a language that
  is not on the site are never listed. An unknown tab is `all`.
- Sort: title and date (`coalesce(published_at, created_at)`), toggled by the column headers. A hierarchical type
  defaults to tree order and a third click on a header (or the "Tree order" button) goes back to it. Tree order sorts
  by `path` with `/` read as a space, so `about/team` comes right after `about` and before `about-us`; with every
  language listed, each language's tree follows the other in the site's order of languages; rows are
  indented by `depth()` only on the All tab with no search and no "Missing" filter (a status tab or a filter can
  hide a row's parent, and a child would be indented under nothing).
- Row: thumbnail (`thumbUrl()` of the image, else a neutral block; fixed 40 px), the title linking to the edit page
  with a Draft or Scheduled badge, the address (`url(false)`), the language codes of the group (the translation
  chips, below; in the Trash tab the record's own code instead, `data-test="entry-language-<id>"`), author, date, and
  the "⋮" menu. On a phone the author and date columns are hidden; the codes stay, under the address.
- Menu: Edit (`edit`), View on site (public records; in a switched-off language a muted note instead,
  `content.form.language_off`, since that address answers 404), Move to trash (`delete`). Translations are added from the
  row's language codes.
- Trash tab: a flat list by `deleted_at`, newest first, with "Removed on <date>" (`deleted_at` + `trash_days`). Menu:
  Restore (`edit`, `RestoreEntry`) and Delete for good (`delete`, `DeleteEntry`).
- The bulk actions are `App\Actions\Content\BulkEntries` (`trash`, `restore`, `delete`, each taking the actor, the
  type and ids and leaving out ids that are not records of the type in the needed state); the page only reports.
- Bulk selection: a checkbox per row and one in the header for the whole page, shown to those who have a bulk action
  (`delete`; in the trash `edit` or `delete`). The picks are the `selected` property, bound from Alpine
  (`x-model="$wire.selected"`), so picking makes no request; a bar above the table shows the count and the actions:
  "Move to trash" (`confirmBulk('trash')`, then `runBulk`: `TrashEntry` per record, parents first, so a page under a
  picked one counts once), in the trash "Restore" (`restoreSelected`, `edit`, no confirmation, parents first; a row
  its parent brought back is skipped, and a record that cannot be restored is a danger toast without stopping the
  others) and "Delete for good" (`confirmBulk('delete')`, one `DeleteEntry::purge()` of
  the picked trashed records). The dialog's count is of the picked rows that exist in that state. Only ids that are records of the type in the right state are acted on. The picks are
  dropped when the tab, the search, a filter, the sort or the page changes, and after an action.
- "Empty the trash" (the Trash tab, `delete`, shown while the trash holds anything): after a confirmation naming the
  count, `EmptyEntryTrash` for the languages the list shows.
- Confirmations (`<x-confirm-modal>`): moving to the trash names the number of subpages going with it
  (`descendantsCount()`); deleting for good is always confirmed and names the number of trashed records under it.
  Results are toasts; a `ProtectedActionException` (a restore conflict) is a danger toast.
- Empty states: no records at all invites creating the first; a search or filter with no match offers "Clear filters";
  an empty trash says how long records stay there; an empty tab says so.
- Opening the list prunes the trash (`PruneEntryTrash`) after the response at most once an hour per type (cache key
  `content.prune_trash`; the prune covers every type), so no cron is required. After an action that empties the page
  the list is on, it shows the last page that still has rows.

`App\Support\Content\EntryListQuery::for($type, $locale, $locales)` (an empty `$locale` means every language in
`$locales`, the site's) builds the list: `tab()`, `search()`, `missingIn()` (a `not
exists` on the group), `sort('title'|'date'|'tree', 'asc'|'desc')`, `isTree()`, `query()`, `paginate($perPage)` and
`counts()` (one aggregate query). It selects only the columns a row shows and eager loads `image`, `author` (`id`,
`name`) and `groupEntries` (`id`, `entry_group_id`, `locale`, `title`, `status`, `published_at`), so the page's query
count does not depend on the number of rows (asserted with 5 and 20 rows). Permissions are checked once per render,
not per row.

**Translation chips** (`partials/admin/content/translation-chips.blade.php`, the list's row and the form's header):
receives `$type`, `$languages` (`ContentLocales::all()`), `$current` (the record's own code), `$records` (the loaded
group records), `$canCreate`, `$fromId` (what the create links copy), `$activeCodes` and optionally `$trashed` (the
group's trashed records keyed by language; only the form passes them). One code per language of the site in a line
that wraps: the record's own filled dark, an existing translation green (published) or amber (draft or scheduled)
linking to its edit page, a trashed one (form only) struck through linking to its read-only page, a missing one dashed
with "+" linking to the create page with `from` and `locale` (a plain dashed code without `create`); a switched-off
language is dimmed. Each carries `data-test="chip-<code>"` and `data-state` (`this`, `published`, `draft`, `trashed`,
`missing`) and a title naming the language, the state and the record. It makes no query, is always visible (no hover,
so it works on a phone) and replaced the hover grid and the "Add translation" submenu. In the list a trashed
translation counts as missing, as for `CreateEntry`: its create link opens the trashed record.

**Translations list** (`partials/admin/content/translations-list.blade.php`, the form's language card; the user asked
for the language box of WordPress and Joomla): a row per other language of the site, `data-test="translation-row-<code>"`
with `data-state` (`Published`, `Draft`, `Scheduled`, `trashed`, `missing`). A language with a record shows its title
linking to its edit page, its state, an edit button and, for an editable saved record, "Unlink"
(`confirmUnlink(<id>)`). A trashed one is struck through and only links to its read-only page. A language without one
offers "Choose" (`chooseTranslation('<code>')`, an editable saved record) and "Create" (`create`; the create page with
`from` and `locale`). A switched-off language is marked. It makes no query.

**Form** (`pages::admin.content.create` and `pages::admin.content.edit`, both on `App\Livewire\Admin\EntryFormPage`
(decision 32; the edit page's own logic is `App\Livewire\Admin\EntryEditPage`, which extends it), the markup in `partials/admin/content/entry-form.blade.php`, the values in
`App\Livewire\Forms\EntryForm`; plain full-page components, no islands, `wire:model` without `.live` everywhere):

- **New record.** `locale` from the query must be a language of the site (`ContentLocales::has()`), else 404; without it
  the list's remembered language, else the default (the list's Create buttons send no `locale`; the "Add
  translation" links send `from` and `locale`). On a multilingual site the language card holds a select "Language of
  the record" instead of the fixed language row: every language of the site (a switched-off one marked), or for a
  translation those its group lacks (trashed records counted). Choosing one calls `changeLocale()` and changes the record in place, with no
  reload and no redirect: the typed values stay, the parent becomes the one that language has (none, or for a
  translation the source's parent in that language, `copyFor()`), the slug is made free in the language
  (`EntryPaths::suggest()` on the slug, else the title), and the action returns the new address prefix and parents'
  paths, which the form's script keeps; the editors take the language from the `entry-locale:changed` event, and the
  address bar drops its `locale` (a translation keeps it next to `from`, updated). It authorizes `create` and ignores
  a language that is not offered (returns null; the select goes back). The status of a new record is Published for an actor with the type's `edit`
  (the user's request, decision 64: it goes live on Save, with the saving time as its date) and Draft, the only one
  they can save, for a creator without it; a translation too. With `from` (a live record of the type, else 404): when the group
  already has that language, trashed or not, the page redirects to that record's edit page (a trashed one opens
  read-only with Restore); otherwise the form is filled from `copyFor()` and the slug suggested from the copied title
  under the mapped parent. The copied author is kept only while they can still be one (`EntryAuthors`); otherwise the
author is the actor, so the select shows a valid choice. Nothing is stored before Save. Without `from` the author is
the actor. If the source went to the trash or was deleted after the form opened, Save refuses with a `form.source`
error instead of creating a record outside the group. Save calls
  `CreateEntry` and goes to the edit page (`navigate: true`, no full load) with a toast ("The record is created.", or "already exists" when the
  action returned an existing translation).
- **Saved record.** `entryId` is locked; the record is loaded with its group (shared field values). Save calls
  `UpdateEntry` and stays on the page, with no redirect and no reload (the scroll position and the caret stay): the
  record is loaded again, the date a publish gave it is put into the form, a toast says "Changes saved", and the
  `entry-saved` browser event (language, state, document title) tells the form's script that its values are now the
  saved ones; the form's language is passed only when it differs from the record's
  language when the form opened (`openedLocale`), so a form left open never undoes a language change made elsewhere. The language select is the new record's one,
  offering the record's language and those its group lacks (trashed ones counted): `changeLocale()` (here it
  authorizes `update` and refuses a trashed record) changes the form in place and stores nothing; the language is
  applied on Save, a hint under the select says so, and the form counts as changed. A record with anything under it
  gets the fixed language row with the reason instead (one `exists` query, only on a hierarchical type of a
  multilingual site). Choosing and unlinking translations are immediate actions of their own, apart from Save and
  without a reload: `chooseTranslation($locale)` (404 for a language the group has or the site lacks) opens a dialog
  with a search over the live records of the type in that language outside the group (one query while it is open, at
  most 20; a record that is someone's translation says for which languages, as it will leave them), `link($id)` runs
  `LinkTranslations`; `confirmUnlink($id)` (the record itself or a live record of its group, else 404) asks, and
  `unlink()` runs `UnlinkTranslation`. All of them need `update` on a record outside the trash; a refusal is a danger
  toast. A record in the trash is read-only and refuses Save (403); trashing
  a record that is already in the trash does nothing (no toast, no redirect). The record the route bound serves the
  first render, so it is loaded once.
- **Header**: a back link to the list (with no `locale`, so the list keeps its own choice), the record's title (or `newLabel()`) with a
  Draft/Scheduled/"In the trash" badge, "View on site" (a public record) or "Preview" (a draft or scheduled one, only
  for a viewer with the type's `edit`, who may preview it; a view-only user gets no link), both opening `Entry::url()`
  in a new tab and only for a saved record outside the trash; a record in a switched-off language shows the muted note
  "This language is switched off, so the page is not on the site yet" (`content.form.language_off`) instead, since its
  address answers 404 for everyone (previewing an off language is not included). Under the title, on a multilingual
  site, the translation chips of the record's group (`data-test="header-translations"`; a new record has them only
  as a translation). Then Save, labelled by what it will do (the form's script, from the status, the date and the
  saved state): "Publish" or "Schedule" for a record that is not that yet, "Save draft" for a new draft, else
  "Save"; the bar at the bottom carries the same button. A new translation shows a callout that the form is a copy
  of its source and, for those who may publish, that saving it as Published puts it on the site at once
  (`data-test="translation-callout"`). Page title:
  `content.form.page_title` (":type: :title") through `->title()`.
- **Left column**: a card with the title, the address (the prefix as text: the site address without the scheme, the
  language prefix when not the default, the type's base, the parent's path; the slug is the input), the body
  (`<x-admin.rich-text>`, its images given as `editorImages`, id => URL with one query for the body and the rich text
  fields) and the excerpt; a "Details" card with `localFields()` when the type has any; the "Search engines" card with a
  live preview (address; title: the SEO title alone, else the title, " - " and the site name, the rule of
  `Entry::documentTitle()`; description falling back to the excerpt) and the SEO title and description.
- **Right column**: "Publication" (status Draft/Published, date and time as `datetime-local` in `admin.timezone` with
  the timezone named under it; a hint when Published with a future date, and when Published without a date); a user
  without the type's `edit` sees the status fixed to Draft with a line that an editor publishes it. "Language and
  translations" (hidden on a site with one language): the record's language (the select, or the fixed row), `n of
  total` and the translations list (with trashed translations; for a saved record with two or more other records also
  "Unlink this record from its translations"); a new record without a source says translations come after saving. A `locale` error
  (the language removed before Save) and a `source` error show in a callout at the top of the form, visible on a
  one-language site too. "For all languages" with `sharedFields()` and the line that a change
  applies to every translation (absent when the type has none); in a translation made by a user without the type's
  `edit` (`sharedFieldsLocked()`) these controls are disabled with a line that changing them needs the right to edit
  (`content.form.shared.locked`), as `CreateEntry` ignores them. "Image" (the media picker in field mode,
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
  then the edit page with a toast; a restore refused by a conflict is a danger toast, and the record is loaded again,
  because the action had already cleared `deleted_at` on the loaded one, so the form still shows it in the trash);
  other read-only forms say they can be viewed only. A user with `create` but neither `view` nor `edit` opens saved
  records read-only too.
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
ContentRouter::PATH_PATTERN)->middleware(LookUpContent::class)->fallback()->name('content')`, so `content` and
`localized.content` (`{locale}/{path}`).
`PATH_PATTERN` is lowercase slugs joined by `/` (`[a-z0-9]+(?:-[a-z0-9]+)*(?:/[a-z0-9]+(?:-[a-z0-9]+)*)*`): any other
address (`/favicon.ico`, `/wp-login.php`, `/.env`, `/about_us`, `/про-нас`, `/about//team`, `/About`, `/en/favicon.ico`)
matches neither copy and stays the router's own 404, before any middleware: no session, no query (asserted, also in the
cached table). Every path the application can store fits it, so `Entry::url()` never fails the route's parameter check.
Both copies are fallbacks:
every explicit route (admin, Fortify, settings, media, `up`, Livewire, the home page) is tried first, and the prefixed
copy still precedes the plain one, so `/de/about` is the German page `about`, never a default-language page `de/about`
(a first segment equal to a catalogue code is always a language). GET and HEAD only, which changes three answers for
slug-shaped addresses: a POST (or PUT, DELETE) to an unknown one answers 405 instead of 404, as with Laravel's own
`Route::fallback()`; a GET to an address that only has a POST route (`/logout`) answers 404 from the content route
instead of 405 (asserted); an OPTIONS request to an unknown one answers 200 with `Allow: GET,HEAD`. The routes serialize and the compiled
(cached) collection keeps both content routes last. `ReservedSlugs` skips them (they carry `SetContentLocale`, and their
first segment is a parameter).

**Lookup before the session** (`App\Http\Middleware\LookUpContent`, on the content routes only, which never serve
Livewire). `bootstrap/app.php` puts it in the middleware priority list right before `StartSession`
(`prependToPriorityList()`), so the router sorts it ahead of the session (after `EncryptCookies` and
`AddQueuedCookiesToResponse`), although the route sits in the `web` group (asserted on both routes). It reads the
language from the address the way `SetContentLocale` does, through the same `ContentLocales` methods:
`codeForPrefix($prefix)` (the `{locale}` parameter, else the default) and `isDefaultPrefix($prefix)`. The default
language's own prefix is passed on untouched, so `SetContentLocale` redirects it (after the session, as before); a
language that is off or not added answers 404 here, before the session. Then `ContentRouter::lookup()`, and the rule
that keeps hidden content indistinguishable from a miss, decided on the request, not on the record
(`LookUpContent::mayBeSignedIn()`: the request carries the cookie named `config('session.cookie')`; its presence is
enough, it is not decrypted):

- **No session cookie** (it cannot come from a signed-in editor): a miss, a record that is not public (draft or
  scheduled) and a redirect to such a record are all the same 404 here, before the session: no session started, no
  cookie, no `site_locale` write, byte-identical responses apart from `Date` (asserted for a draft, a scheduled page, a
  redirect to a draft and a missing path, and for `/wp-admin`, `/xmlrpc`, `/en/no-such-page` and a missing child). A
  public record and a redirect to a public record go on.
- **Session cookie present**: everything goes on, a miss included, with the lookup in the request attribute
  `ContentRouter::LOOKUP_ATTRIBUTE` (null for a miss); the controller answers a miss with 404 without looking again,
  and `resolve()` decides on a hidden record after the session, where an editor gets the preview. A miss and a hidden
  record are then the same 404 after the session (asserted: status, headers apart from `Date` and the cookies' values,
  cookie names, body). Such a miss costs a session read; probing bots send no cookies.

Either way a hit is looked up once.

**Lookup** (`ContentRouter::lookup($locale, $path): ?ContentLookup`, no viewer, no session):

1. A first segment equal to a registered type's `base($locale)`: the rest is that type's record path. A bare base is the
   type's list, which a later part builds: null (404) for now, with no query.
2. Otherwise a record of the types without a base (`pages`) by the full path (one indexed lookup on `type, locale,
   path` for any depth; with several such types the earliest registered wins), whatever its status; trashed records
   are left out.
3. On a miss, one lookup in `entry_redirects` (`type, locale, path`, joined to the live record by a subquery): found →
   a `ContentLookup` with `redirect: true` and that record.
4. Null.

`ContentLookup` (`entry`, `redirect`) is what the address holds before anything is known about the viewer.

**Resolution** (`ContentRouter::resolve($locale, $path, $viewer, $lookup = null): ?ContentMatch`), in the request's
language (`ContentLocales::current()`, set by `SetContentLocale`, which also removes `{locale}`): takes the middleware's
lookup, else looks up itself (direct calls). A record that is not public is null unless the viewer may preview it
(below); a redirect is `ContentMatch::redirect($record->url())` under the same rule; a record shown loads its public
translations and its image.

`lookup()` checks `PATH_PATTERN` again, anchored with `\z` so a trailing newline never matches (for direct calls, and
because MySQL compares paths without regard to case), so a path with a capital letter is never matched by the database. A trailing slash gets the framework's trim, as for every
route of the application (`/admin/` behaves the same): `/about/` shows `/about`. On Apache the kit's `public/.htaccess`
redirects it; on nginx both addresses answer until a redirect rule or the canonical tag (head tags, a later part) is
added. No other normalising.

`ContentMatch` (`entry`, `preview`, `redirectTo`): `ContentMatch::entry($entry, $preview)` or `::redirect($url)`.

**Visibility.** A public record (`isPublic()`) is shown to everyone. A draft or scheduled record resolves only for a
signed-in user who may edit its type (`EntryPolicy::preview`), with `preview: true`; everyone else gets the same 404 as
for a missing page (status, `Cache-Control` and body; no redirect lookup is made for it either). That decision needs the
user, so it is made after the session for a visitor with a session cookie, and before it, like a miss, for one
without (`LookUpContent`, above); either way the hidden record's 404 cannot be told from a miss's. A trashed record is
404 for everyone.

**Controller** (`App\Http\Controllers\ContentController`): null → 404; a redirect → 301 with `Cache-Control: no-cache`
(the old path may be taken by a new record later, so a browser must not keep it for good); else the view `site.entry`
with the record, the body through `BodyRenderer::render()`, `preview` and `editUrl` (the admin edit page, only for a
viewer with the type's `edit`). A preview answers `Cache-Control: no-store`.

**Alternates.** The controller passes `ContentLocales::useAlternates()` the addresses of the group's records that are
public and in an active language (the record itself included when public), in the site's order of languages. For a
viewer who may edit the type the drafts and scheduled translations are included too (the same one query without the
`public()` filter), so the switcher leads an editor to a translation before it is published, where it opens as a
preview; that viewer's `hreflang` lists them as well, which no crawler sees. The
`hreflang` partial prints them plus `x-default` (the default language's address, left out when the default language has
no public translation), and nothing when there is only one. The switcher lists every active language.

**Templates** (a folder of their own so a theme can replace them):

- `layouts/site.blade.php`: `<html lang dir>` from the content language, `<title>` from `$pageTitle`, which the page
  builds whole (`Entry::documentTitle()`: the `seo_title` alone when set, else the title, " - " and the site name; the
  form's search preview uses the same rule and separator), `<meta name="robots" content="noindex">` in preview, the
  favicons, the public stylesheet only (`@vite('resources/css/site.css')`, below), the color scheme
  (`partials.color-scheme`), a small inline script that sets the `dark` class from the stored appearance (Flux's
  `flux.appearance`, when the visitor has used the admin) or else the visitor's system light/dark preference (no Flux,
  Livewire or admin JavaScript is loaded), `hreflang`,
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

**Stylesheet.** `resources/css/site.css` is a Vite entry of its own: Tailwind with automatic source detection off
(`source(none)`) and `@source` limited to `layouts/site`, `site/*`, `partials/site/*` and the two logo components, the
shared theme (`resources/css/theme.css`: the dark variant, fonts, zinc, accent and sidebar variables, also imported by
`app.css`) and the body typography (`rich-text-content.css`). Built: about 16.6 KB (4.3 KB gzipped), against about
455 KB (48 KB gzipped) for the admin's `app.css`, which public pages no longer load. A class used in a site template
outside those paths must be added to its `@source` list.

**Cost per page view** (asserted: at most 5 queries more than the home page in the same setup, and at most 4 that read
`entries`, `entry_redirects` or `media`; the home page makes none in the tests):

1. `select * from entries where locale = ? and path = ? and type in (?) and deleted_at is null`
2. `select id, entry_group_id, type, locale, path from entries where entry_group_id in (?) and status = 'published' and
   published_at <= ? and locale in (<active>) and deleted_at is null` (the public translations; skipped on a site with
   one active language, which has neither `hreflang` nor a switcher: a plain page there is one query, asserted)
3. `select * from media where id in (?)`, the record's image, only when it has one
4. `select * from media where id in (…)`, the body's images, only when the body has any

Plus the site logo (one query, only when a custom logo is chosen) and, for a signed-in viewer, what the user and
permission checks cost. The figures exclude the session's own queries, which depend on the session driver and are the
same as for the home page. Content queries per branch (asserted): without a session cookie a miss is two (1 and the
redirect lookup), a draft or scheduled page one, a redirect to a draft two, none of them starting a session; with a
session cookie the same counts plus the session's and the user's own queries; a public redirect two; a bare base none;
an address that is not slug-shaped never reaches the controller.

## Tests

`tests/Feature/Content/PublicContentTest.php`: a page in the default language and its translation under the prefix, a
nested path, a record under a type's base and the bare base, the trailing slash, addresses that cannot be content (the
router's 404 with no query and no session), `GET /logout`, `url()` for the deepest and longest stored shapes, the cost
on a one-language site, the hidden record's 404 equal to a missing one, the image, the SEO title, the
rendered body, no Livewire or script files, the public stylesheet instead of the admin's; preview (draft, scheduled and trashed are 404 for a guest and a user without
`pages.edit`; the strip, `noindex`, `no-store` and the edit link for an editor; the scheduled date in the site's
timezone; the Edit link on a published page); `hreflang` (public translations in active languages, `x-default`, none for
one, no `x-default` without the default language) and the switcher (an unpublished translation linked only for an
editor); `/de/about`; languages off or not added; no leak of
alternates into the next request; redirects in both languages, to a draft only for an editor, after a `move()`; every
other route unchanged (by the router's match), reserved slugs, `url()` from the route without a query, the compiled route
cache; the query budget and the cost of a miss and a redirect; `LookUpContent` ordered before `StartSession` on both
routes, slug-shaped misses answered with no cookie, no `site_locale` and at most two queries, hidden content (draft,
scheduled, a redirect to a draft) answered exactly like a miss with and without a session cookie, the session still
started for a public page and a public redirect, an editor with a session still previewing; a trailing newline never matched by `lookup()`; the list's "View on site"
and the form's "Preview".
`tests/Feature/Admin/Content/EntryIndexTest.php`: access (guest, 403, unknown type), the sidebar item and group, tabs
and counts, Cyrillic search, tree order and indent for three levels (with an `about-us` sibling), title sort and back to
the tree, `missing`, the list's language (every language by default with the code per row and the trees in the
site's order, the chosen one in the session, fallback), bulk trash, restore and delete for good with their
permissions and confirmations, emptying the trash for the listed languages, the picks dropped by a filter, the row's language codes and their links, one row per group with every language listed (the group found by a tab
and a search through any of its records), the trash listing records, the one-language
site, the Create button, trash/restore/delete for good with their permissions and confirmation texts, the row menu, empty
states, the query string, the search term cut at 200 characters, the same query count for 5 and 20 rows, the hourly
prune, the indent only on the full tree, that the create and edit pages authorize, `edit` or `create` without `view`
opening the list, the sidebar item and a record, and the translation form refusing a source the actor may not view.
`tests/Feature/Admin/Content/EntryFormTest.php`: creating through the form (stored, redirect, toast), the language from the
query (404 for one not on the site), the page title, `suggestSlug()` (free, suffixed, under a parent), editing title,
parent and slug, errors on their fields with the body kept, the parent options (no own subtree, no other language),
a tampered parent and a language removed before saving, a translation from `copyFor()` saved into the group, the
redirect to an existing or trashed translation, view-only (read-only, `save` and `confirmTrash` refused), a creator
without `edit` (status locked, author as text, publishing refused), trash with its confirmation, a trashed record
(read-only, removal date, Restore with and without `edit`, a restore refused by a conflict still shown in the trash),
another type's record 404, View on site / Preview (no Preview for a view-only user, the note for a switched-off
language in the form and the list), the shared values locked in a creator's translation and kept on save, a type
with fields (`ProductsType`: one control per field, local and shared values stored in place, a field error), no field
cards for `pages`, saving in place (no redirect, the `entry-saved` event, the date a publish gave), the header's
language codes and the translation callout, the language card's rows and actions (trashed included; none of the actions with `view` only) and
its absence on a one-language site, the Published default of a new record, the
editor's images and the detached picker, the same query count for few and many translations and pages, and the media
library's usage link to the edit page (also for a trashed record).
`tests/Feature/Admin/Content/EntryTranslationsTest.php`: a saved record's language changed on Save (nothing stored
before, the old address redirecting to the new one), the parent's translation and a free slug in the new language, the
refusals (a language the group has, in the trash too, one not on the site, a page with pages under it, no `edit`, a
trashed record); choosing an existing record (candidates, search, the emptied group gone, `updated_at` kept), a chosen
record leaving its former translations, the refused links, the permissions and 404s; unlinking a translation and the
record itself (the copy of the shared values, the others staying together) and its refusals.
`tests/Feature/Console/MakeContentTypeTest.php`: the generator on copies of the project's own config and language
files (the class, the config entry, the lines in both languages), the options, every refusal changing nothing, and a
file of another shape left alone.
`tests/Feature/Content/ContentRegistryTest.php`: the registry and `pattern()`, the types of the config list in its order, a duplicate key and invalid bases refused, `pages`, the permissions after a
sync and for the editor, every field kind's round trip and rules, shared and local fields, media ids.
`tests/Feature/Models/EntryTest.php`: public/scheduled/trashed visibility, the unique indexes, translations, the
tree, the null-on-delete keys, `url()`, `fieldValue()` and the search index. The
test-only `Tests\Fixtures\ProductsType` (`tests/fixtures/ProductsType.php`) has one field of every kind with
`price` shared; tests register it themselves.
`tests/Feature/Admin/Content/RichTextComponentTest.php`: the editor component's binding config (no `wire:model` on the page), the image button only with `media.view`, read-only without a toolbar. The editor itself runs only in a browser and has no automated test; `isContentEdit()` and `normalizeHref()` were checked once with a Node script against the installed Tiptap (the extension manager's plugins on a headless editor), not kept in the suite. The form's browser side (slug following, SEO preview, leave-page warning, Ctrl/Cmd+S, scrolling to an error) has no automated test either.
`tests/Feature/Content/BodyTest.php`: hostile inputs as a dataset with the exact output, allowed markup kept, Cyrillic round trip, long body, a body of `&` under the form's limit kept whole, `mediaIds()`, `withoutMedia()`, rendering (one query, versioned URL, dropped images, alt fallback, no query without images).
`tests/Feature/Content/EntryPathsTest.php`: suggested slugs, the slug rule (a trailing newline, the message for a holder in the trash), `move()` (descendants, redirects, the same
query count for 2 and 50 descendants, redirect rows that already exist).
`tests/Feature/Actions/Content/EntryTrashTest.php`: trashing a tree (one instant, out of `public()`, the path still
taken, `descendantsCount()`), the same query count for 2 and 30 descendants, restoring exactly what was trashed
together, a child trashed before its parent restored to the top level while the parent is in the trash (suffix for a
taken path and for a reserved name) and the parent restored later without it, the retry when the new path is taken
meanwhile (and the refusal when it is taken twice), deleting for good (every trashed record under the page, the count
equal to `descendantsCount()`, redirects, usages, an emptied group, a group kept for another language), no live record
under a trashed one, the address free again after a delete for good (a new `about` with a child `team`), a live record
refused, permissions, pruning (31 and 29 days, a child trashed 40 days ago with or without its parent, a record under
a pruned one trashed after the cut-off), the command and its schedule, a file deleted while its record is in the
trash, and a record restored between the purge's selection and its delete surviving with its usages and redirects. `tests/Feature/Models/MediaUsageTest.php` checks that `forgetAll()` reaches
a trashed record and a `Setting`; `tests/Feature/Localization/ContentLanguagesTest.php` the language removal rule.
`tests/Feature/Actions/Content/SaveEntryTest.php`: create (group, path, author, cleaned body, usages, a redirect row
taken over), update (slug and parent moves, type and language kept), translate (group, `copyFor()`, parent mapping),
a creator without `edit` (publishing refused, translating from `copyFor()` makes them the author, a translation leaving
the shared values and their usages alone while their own new record sets them), `edit` or `create` alone passing
`viewAny`/`view`, a descendant
path pushed past 255 characters (slug and parent), a trashed record not updated, the existing translation returned
(also trashed, also after losing the race: a query listener inserts the competing
record right after the action's own look-up, once failing validation and once the unique index), publishing dates
and the timezone, shared fields, tampered values as a dataset (nothing stored), permissions, the policy,
`EntryAuthors`, and usages and `forgetMedia()` for every kind (a test type with a file field, a gallery and a shared
gallery).

## Known limitations

- `/<default code>/<anything slug-shaped>` is passed on to `SetContentLocale`, which starts the session and redirects
  it to the address without the prefix, where the visitor now carries a session cookie, so a miss there is answered
  after the session.

## Not included yet

Lists of a type under its base, head tags beyond the title (description, canonical, Open Graph), `sitemap.xml` and the
home page setting (a later part).

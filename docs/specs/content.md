# Content

Content types (pages now, posts later) and their extra fields. The registry and the record tables and models
and the slug and path rules exist so far: there are no routes or admin screens yet. Design: `docs/superpowers/specs/2026-11-14-content-types-design.md`.

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
| `perPage()` | admin list page size, 20 by default |
| `permission($action)` | `"{key}.{action}"` |

`App\Support\Content\ContentTypes` (singleton) holds the registered types: `register()`, `all()`, `find()`,
`get()` (throws on an unknown key) and `pattern()`, an alternation of the keys for route constraints (it
matches nothing while no type is registered). Keys follow the permission segment format.

`App\Providers\ContentServiceProvider` binds the singleton and registers `PagesType` (key `pages`,
hierarchical, no base, no fields). In `boot()` it registers `view`, `create`, `edit` and `delete` for every
type with `PermissionRegistry`, so `permissions:sync` and the role matrix see them. Publishing and restoring
need `edit`; trashing and deleting for good need `delete`. The `editor` role gets the four `pages.*`
permissions by default; `admin` gets them through `*`.

`config/content.php` → `trash_days` (30): how long a trashed record is kept. Nothing uses it yet.

## Fields

`App\Support\Content\Fields\Field` (`Text`, `Textarea`, `RichText`, `Number`, `Date`, `Toggle`, `Select`,
`MediaFile`, `Gallery`) is built with `Field::make($name)` and chained `label($key)`, `shared()` (the value is
the same in every language of a record group) and `required()`. `Select` adds `options()`.

| Method | Meaning |
|---|---|
| `rules()` | validation rules for the value (`required` or `nullable`, then the kind's own) |
| `toStorage($formValue)` | text trimmed, empty becomes null; number numeric; date `Y-m-d`; toggle bool; media id int; gallery list of ints |
| `toForm($stored)` | the value as the form binds it (null becomes `''` for text, number, date and select) |
| `view()` | Blade partial name under `partials.admin.content.fields`; the partials come with the editor |
| `mediaIds($stored)` | media ids a value refers to; empty except for `MediaFile` and `Gallery` |

A toggle has no required state: it is always true or false.

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
| `url($absolute = true)` | the site address, the language prefix (none for the default content language, `/<code>` for the others), the type's base for that language, then `path`; built by hand until the public route exists |
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
| `move($entry, $newPath)` | for the caller's transaction: sets the record's path, rewrites the path of every descendant (same type and language, trashed included, matched by the old path followed by `/`) with one `UPDATE`, deletes the redirect rows whose path a record now holds, and stores every old path in `entry_redirects` with one upsert (an existing row for that path is pointed at the record). Five queries whatever the number of descendants. The SQL concat is the one driver-specific part (`||` on SQLite, `CONCAT` on MySQL) |
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

## Tests

`tests/Feature/Content/ContentRegistryTest.php`: the registry and `pattern()`, `pages`, the permissions after a
sync and for the editor, every field kind's round trip and rules, shared and local fields, media ids.
`tests/Feature/Models/EntryTest.php`: public/scheduled/trashed visibility, the unique indexes, translations, the
tree, the null-on-delete keys, `url()`, `fieldValue()` and the search index. The
test-only `Tests\Fixtures\ProductsType` (`tests/fixtures/ProductsType.php`) has one field of every kind with
`price` shared; tests register it themselves.
`tests/Feature/Content/BodyTest.php`: hostile inputs as a dataset with the exact output, allowed markup kept, Cyrillic round trip, long body, `mediaIds()`, `withoutMedia()`, rendering (one query, versioned URL, dropped images, alt fallback, no query without images).
`tests/Feature/Content/EntryPathsTest.php`: suggested slugs, the slug rule, `move()` (descendants, redirects, the same
query count for 2 and 50 descendants, redirect rows that already exist).

## Not included yet

The public route, the admin screens, the editor and the field partials. `BodySanitizer` is not called by anything yet.

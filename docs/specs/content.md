# Content

Content types (pages now, posts later) and their extra fields. The registry, the record tables and models, the
slug and path rules, the body sanitizer, the save actions and the trash exist so far: there are no routes or admin screens yet. Design: `docs/superpowers/specs/2026-11-14-content-types-design.md`.

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
| `toStorage($formValue)` | text trimmed, empty becomes null; number numeric; date `Y-m-d`; toggle bool; media id int; gallery list of ints |
| `toForm($stored)` | the value as the form binds it (null becomes `''` for text, number, date and select) |
| `view()` | Blade partial name under `partials.admin.content.fields`; the partials come with the editor |
| `mediaIds($stored)` | media ids a value refers to; empty except for `MediaFile` and `Gallery` |
| `holdsMedia()` | true for `MediaFile` and `Gallery`: their usages are recorded |
| `withoutMedia($stored, $id)` | the value without a file being deleted: a file field emptied, the id taken out of a gallery |

A toggle has no required state: it is always true or false. A malformed date is stored as null (its rule reports
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
| `descendantsCount()` | how many descendants are in the record's own state (live ones for a live record, trashed ones for a trashed one), one query; for the confirmation text |
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
record, `shared.<name>` on the group. A new record with no files makes no usage query.

`Entry::copyFor($locale)` is the form data of a new translation: title, body, excerpt, SEO, image, author and every
field (shared ones carry the group's values), `parent_id` = the parent's translation in that language or null, an
empty slug, `draft` and no date. Nothing is stored.

`Entry` and `EntryGroup` implement `UsesMedia`. `Entry::mediaUsageLabel()` is "Page: <title>" (the type's label);
`mediaUsageUrl()` is the edit page for a viewer who may view the type (null until the admin form's route
`admin.content.edit` exists); `forgetMedia()` clears `image`, removes the file's images from `body`
(`withoutMedia()`) and empties a `fields.<name>` value or takes the id out of a gallery. `EntryGroup` is named after
its record in the default content language ("Page: <title> (all languages)") and empties `shared.<name>` the same
way. Trashed records are reached too: `MediaUsage::usable()` loads with `withTrashed()`, so a file deleted while its
record is in the trash is gone from the record (image cleared, body without its images) when it is restored.

## Trash

Soft deletes are the trash. A trashed record keeps its path, which stays taken for slugs (`EntrySlug`, `suggest()`)
while it is there; `public()` leaves it out.

| Action | Permission | Meaning |
|---|---|---|
| `TrashEntry::handle($actor, $entry): int` | `delete` | trashes the record and its live descendants with one shared `deleted_at` (to the second), in one `UPDATE` whatever their number; returns how many (0 when the record already was in the trash) |
| `RestoreEntry::handle($actor, $entry): Entry` | `restore` (`edit`) | restores the record and the descendants carrying exactly its `deleted_at` (trashed with it); a child trashed earlier on its own keeps its own time and stays. A record below the top level whose parent is in the trash or gone (deleting for good nulls `parent_id`) becomes top level: the slug gets a suffix when that path is taken or reserved (`suggest()`), and `move(..., keepOldPaths: false)` carries its descendants' paths along without redirects. A live record is returned unchanged |
| `DeleteEntry::handle($actor, $entry): void` | `forceDelete` (`delete`) | only a trashed record (`ProtectedActionException`, `content.entry.not_trashed`); removes it and the descendants trashed with it (same `deleted_at`, the set a restore would bring back). A descendant trashed earlier on its own stays in the trash and is restored at the top level |
| `PruneEntryTrash::handle(): int` | none (decision 16) | deletes for good what was trashed more than `content.trash_days` days ago, 200 ids per chunk, a transaction per chunk |

`DeleteEntry::purge($entries)` (records loaded with `id` and `entry_group_id`) is the removal both share, without authorization: the records' media usages, the rows
(their redirects follow by foreign key), then every group left with no record (trashed ones counted) with its
usages. Five queries whatever the number of records. A group that still has a record in another language stays.

`content:prune-trash` prints "Removed N trashed record(s)." and runs `daily()` in `routes/console.php` next to
`media:prune-deleted`. The admin list will prune too, so no cron is required.

A content language that has records, trashed ones included, cannot be removed (`RemoveContentLocale`, see the
languages spec).

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
`tests/Feature/Actions/Content/EntryTrashTest.php`: trashing a tree (one instant, out of `public()`, the path still
taken, `descendantsCount()`), the same query count for 2 and 30 descendants, restoring exactly what was trashed
together, restoring to the top level after the parent was deleted for good or while it is in the trash (suffix for a
taken path and for a reserved name), deleting for good (redirects, usages, an emptied group, a group kept for another
language), a live record refused, permissions, pruning at 31 and 29 days, the command and its schedule, and a file
deleted while its record is in the trash. `tests/Feature/Models/MediaUsageTest.php` checks that `forgetAll()` reaches
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

The public route, the admin screens (and the list's own prune), the editor and the field partials.

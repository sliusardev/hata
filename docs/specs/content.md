# Content

Content types (pages now, posts later) and their extra fields. The registry and the record tables and models
exist so far: there are no routes or admin screens yet. Design: `docs/superpowers/specs/2026-11-14-content-types-design.md`.

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

## Tests

`tests/Feature/Content/ContentRegistryTest.php`: the registry and `pattern()`, `pages`, the permissions after a
sync and for the editor, every field kind's round trip and rules, shared and local fields, media ids.
`tests/Feature/Models/EntryTest.php`: public/scheduled/trashed visibility, the unique indexes, translations, the
tree, the null-on-delete keys, `url()`, `fieldValue()` and the search index. The
test-only `Tests\Fixtures\ProductsType` (`tests/fixtures/ProductsType.php`) has one field of every kind with
`price` shared; tests register it themselves.

## Not included yet

Redirect writing, the public route, the admin screens, the editor and the field partials.

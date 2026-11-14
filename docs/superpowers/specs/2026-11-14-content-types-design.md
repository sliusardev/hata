# Content types (pages, posts, taxonomies): design

Status: draft for the user's approval, 2026-11-14. Nothing is built.

## Intent

The kit has users, roles, settings, a media library and content languages, but no content. This stage adds
it: a registry of content types with pages and posts as the first two, taxonomies with categories and tags
as the first two, an admin to edit them and plain public pages that show them.

The user wants one universal mechanism rather than a module per kind of content ("як у вордпресі"): later
they will add types of their own with their own fields, a product among them, when a real site
(a three-language catalogue with news, articles and static pages, now on Joomla) moves to the kit. A cart
and orders will be separate modules written later and are not content.

Standing expectations that shape the how: no extra load per request, and every admin screen is shown to
clients, so it must look finished.

## Goals

1. A registry of content types declared in code; `pages` (hierarchical) and `posts` (dated, with categories
   and tags) are registered by the kit.
2. A registry of taxonomies declared in code; `categories` (tree) and `tags` (flat), both attached to `posts`.
3. Field types a content type can declare: text, textarea, rich text, number, date, toggle, select, media
   file, gallery; each either per language or shared by all translations.
4. One record per language, linked to its translations; statuses, scheduled publication, trash.
5. A generic admin: one list page and one form for every type, one list and one form for every taxonomy.
6. Public addresses in every content language, plain neutral templates, SEO tags, `sitemap.xml`, redirects
   from changed addresses, a setting that chooses the home page.
7. Four permissions per type and one per taxonomy, through the existing permission registry.

## Non-goals (this stage)

Themes and their templates; content blocks; revision history; an editorial workflow (`publish`,
`edit_others`, "in review"); navigation menus; site search; RSS; comments; the shop (cart, orders, payment);
types or fields built in the admin; permissions per language; the original shown beside a translation; an
"original changed since translation" mark; changing a type's address base in the admin; a category in a
post's address; non-ASCII slugs; importing the old site or its redirects.

## Anti-goals

- A table with one row per field value (`wp_postmeta`). Display fields are JSON on the record; data a type
  searches or sorts by gets real columns in a table of its own.
- A copy of the admin pages, tables or permissions logic per type.
- Livewire, settings-dependent routes or any session write beyond what a public page makes today, on
  public content pages; more than a handful of indexed queries per page view. (Pages stay in the `web`
  group, because the draft preview needs the signed-in user; making them cookie-free for a proxy or CDN is
  not a goal of this stage.)
- Shared values that silently overwrite translations: only what a type declares shared is shared, and the
  form says so.

## Approaches

**What a content type is.** (A) fixed `Page` and `Post` models, (B) one entries table and a registry of types
in code, (C) types and fields built in the admin. The user picked **B**: A means a copy per new type, C is a
product of its own and stays possible later.

**Extra fields.** A separate table of field values was considered at the user's question and rejected (many
joins to filter, everything a string, a second query to show a record). Chosen: JSON for display fields, and
a type may own a table with real columns for data it queries by (a product's price, SKU, stock). No type uses
that in this stage; the registry only leaves room for it.

**Translations.** First proposed: one record with a tab per language. The user rejected it: **one language,
one record**, as in WordPress and Joomla, each with its own form, status and address, and links to the other
languages in the form's sidebar. Then: should the records of one translation set share data? (1) share
categories, image, author; (2) copy on creation, independent after; (3) copy everything except what the type
marks shared. The user picked **3**, with **categories and tags shared** by all translations.

**Storage.** (A) a row per language in `entries` plus an `entry_groups` row per translation set, which
categories, tags and shared fields hang on; (B) rows per language joined only by an id in a column. The user
picked **A**: B gives shared data nothing to reference with integrity.

**Declaring types.** (A) PHP classes, (B) config arrays. The user picked **A**: checked by PHPStan, and
validation and field behaviour need classes anyway.

**Body editor.** (1) a rich text editor, (2) blocks, (3) the editor now and blocks later as another field
type. The user picked **3**, with two new dependencies approved: **Tiptap** (npm) and
**`symfony/html-sanitizer`** (composer).

**Addresses of listed types.** (1) `/<type base>/<slug>`, (2) with the category in the path, (3) no base.
The user picked **1**.

**Permissions.** (1) view/create/edit/delete per type, (2) plus publish and edit-others. The user picked
**1**; 2 can be added later because the author is stored from the start.

## Decided with the user

- A record that has no published translation in a language is **404** there and absent from that language's
  lists; nothing falls back to the default language.
- Categories and tags are shared by all translations of a record; each term has its own name, slug and
  description per language.
- Deleting moves a record to a **trash** for 30 days. Revision history comes later.
- Everything about translations sits in one sidebar card of the form; nothing is added next to the title.
- With many languages (the user's test: 12): the card shows a counter, a progress bar and a grid of language
  codes; the list shows one language at a time, a bar with a count instead of badges, and a filter "missing
  translation in".
- Public side of this stage: neutral templates, head tags, `sitemap.xml`, a language switcher. Themes are a
  later stage.
- Built in three parts, each checked by the user in a browser before the next (see Parts).

## Data

All tables are new. Names of types and taxonomies are their plural keys (`pages`, `posts`, `categories`,
`tags`), used alike as the stored value, the admin address segment and the permission group.

**`entry_groups`**: one row per translation set.
`id`, `type`, `shared` (JSON, values of fields declared shared), timestamps.

**`entries`**: one row per language.

| Column | Notes |
|---|---|
| `entry_group_id` | FK; unique with `locale` |
| `type`, `locale` | `type` repeats the group's, for the indexes |
| `parent_id` | nullable FK to `entries`; same type and locale; hierarchical types only |
| `author_id` | nullable FK to `users`, null on delete |
| `title`, `slug` | |
| `path` | the address inside the type and language: ancestors' slugs joined with `/` for a hierarchical type, the slug otherwise; unique with `type`, `locale` |
| `body` | sanitized HTML, nullable |
| `excerpt`, `seo_title`, `seo_description` | nullable |
| `image_id` | nullable FK to `media`, null on delete as a safety net |
| `fields` | JSON, values of per-language fields |
| `status` | `draft` or `published` (`App\Enums\EntryStatus`) |
| `published_at` | nullable; set to now on first publish when empty |
| `search_index` | `HasSearchIndex`, from title and slug |
| `deleted_at`, timestamps | soft deletes are the trash |

Indexes: unique (`entry_group_id`, `locale`); unique (`type`, `locale`, `path`); (`type`, `locale`, `status`,
`published_at`); `parent_id`.

"Scheduled" is not a stored status: it is `published` with `published_at` in the future. **Public** means
`published`, `published_at <= now()`, not trashed. Nothing runs at the scheduled moment; the query decides.
A trashed record keeps its path reserved until it is deleted for good.

**`entry_redirects`**: `type`, `locale`, `path` (an old path), `entry_id` (FK, cascade); unique (`type`,
`locale`, `path`). Written when a path changes (the record's own and every descendant's). A record saved
with a path that is in this table removes that row.

**`terms`**: `id`, `taxonomy`, `parent_id` (nullable, tree taxonomies), timestamps.

**`term_translations`**: `term_id`, `taxonomy`, `locale`, `name`, `slug`, `path` (for a tree: the slugs of
the ancestors that have a name in that language, then its own; an ancestor without one is skipped),
`description`, `search_index`; unique (`term_id`, `locale`); unique (`taxonomy`,
`locale`, `path`).

**`entry_group_term`**: `entry_group_id`, `term_id`; primary key on both, plus an index (`term_id`,
`entry_group_id`).

Models: `EntryGroup`, `Entry`, `EntryRedirect`, `Term`, `TermTranslation`, with factories.

## Registry

`App\Support\Content` (inside the existing `app/Support` tree):

- `ContentTypes` (singleton): `register()`, `all()`, `find($key)`, `pattern()` for route constraints.
  `Taxonomies` likewise.
- `ContentType` (abstract): `key()`, label and plural label (translation keys), `icon()`, `hierarchical()`,
  `base(string $locale): ?string` (null = lives at the root, has no list page; otherwise the list's address
  and the prefix of its records), `taxonomies()`, `fields()`, `perPage()`.
- `Taxonomy` (abstract): `key()`, labels, `hierarchical()`, `base($locale)`, the types it attaches to.
- `Fields\Field` and its kinds: `Text`, `Textarea`, `RichText`, `Number`, `Date`, `Toggle`, `Select`,
  `MediaFile`, `Gallery`. Each has a name, a label key, validation rules, a cast between stored and form
  value, a Blade partial for the form, and `->shared()`. A field is per language unless shared; per-language
  values live in `entries.fields`, shared ones in `entry_groups.shared`.

A base is declared per language with one fallback, so a language added later in the admin (no deploy) uses
the fallback. Bases are code, never settings, so public routing does not depend on the database.

Kit types: `pages` (hierarchical, no base, no taxonomies), `posts` (base `blog`, categories and tags).
Neither declares extra fields. Because of that, the field kinds are exercised by a type registered only in
tests. The kit registers its types and taxonomies from a service provider; a module does the same.

Every record has title, slug, body, excerpt, image, SEO fields, status, date and author. A way for a type to
switch some of these off is not built until a type needs it.

## Translations

- A translation is created from an existing record: `create?locale=pl&from={entry}` opens the form filled
  with a copy of the source (title, body, excerpt, per-language fields, image, author, SEO; the parent
  becomes the source parent's translation in that language when there is one). The slug is generated from
  the title. Status starts as draft. Nothing is stored until the form is saved; if that group already has a
  record in the language, the request opens it instead.
- After creation the copies are independent. Shared: the group's terms, the fields a type marked shared, and
  the rows of a type's own table (keyed by group).
- A record's language is fixed once it is created.
- A new record takes the language the list is showing.

## Addresses and routing

- Page: `/<path>`, e.g. `/about`, `/info/delivery`; in another language `/<code>/<path>`.
- Listed type: `/<base>` is the list (paginated with `?page=`), `/<base>/<slug>` a record.
- Term: `/<taxonomy base>/<term path>`, a paginated list of the public records in it.
- Home: Site settings → General gets "Home page", a picker of pages; it stores the page's group. `/` shows
  that group's public record in the request's language. The page's own address redirects (301) to `/`.

One wildcard route inside `Route::localized()`, registered so that it is tried after every other route (the
way `Route::fallback()` is), handled by a plain controller. `App\Support\Content\ContentRouter` resolves a
path in the request's language, in this order: a type base (list, or a record by `type, locale, path`), a
taxonomy base, then a page by its full path. On a miss it looks the path up in `entry_redirects` and answers
301 to the record's current address; else 404. One indexed query finds a page of any depth.

Slugs: `^[a-z0-9]+(-[a-z0-9]+)*$`, generated from the title with `Str::slug` until edited by hand (as role
names are). A top-level page slug may not be a reserved slug (`ReservedSlugs`) nor a type or taxonomy base
of that language. A generated slug that is taken gets a numeric suffix; a typed one is a field error.

Changing a slug or a parent rewrites the `path` of every descendant in one transaction and records the old
paths as redirects.

A draft or scheduled record opens at its real address for a signed-in user who may edit that type, under a
visible "Draft" strip and with `noindex`; everyone else gets 404.

## Body, editor, sanitizing

- Tiptap with its starter kit plus link, underline and an image node. The editor lives in a `wire:ignore`
  block driven by Alpine and hands its HTML to the Livewire form on save, not on every keystroke.
- Images are chosen from the media library. The existing `<livewire:media-picker>` is a form field bound
  with `wire:model` and cannot be opened from outside to return a pick, so Part 1 gives it a mode (or a
  wrapper) the editor can open and receive the chosen file from. They are stored as `<img data-media-id="…" alt="…">`. The
  address is filled in when the page is rendered, from the file's current URL, because a crop or replace
  changes it. Cost: one `whereIn` query per rendered body that has images.
- On save the HTML goes through `symfony/html-sanitizer` with an allowlist: paragraphs, `h2`–`h4`, bold,
  italic, underline, strike, links (`href`, `target`, `rel`), lists, blockquote, `br`, `hr`, `code`/`pre`
  and the image node. Everything else is dropped. The stored HTML is not sanitized again on output; the only change at render is resolving the
  images, and an image whose file is gone is dropped.
- `Entry` implements `UsesMedia` for `image`, `body` and media fields, so the library shows where a file is
  used and deleting a file clears the image, removes its `<img>` from the body or empties the field, inside
  the delete's transaction (as the logos do). Shared media fields are recorded against the `EntryGroup`.
  **Records in the trash are cleaned too**: `MediaUsage::forgetAll()` loads usables through `morphTo`, which
  skips soft-deleted models today, so it must include them; a restored record never returns with a dead
  image.
- `Gallery` keeps the order the files were picked in; the picker has no reordering yet.

## Admin

Routes in `routes/admin.php`, constrained to registered keys:
`admin/content/{type}` (list), `…/create`, `…/{entry}/edit`; `admin/taxonomies/{taxonomy}`, `…/create`,
`…/{term}/edit`. Livewire SFC pages under `pages/admin/content` and `pages/admin/taxonomies`; a base class
in `App\Livewire\Admin` where logic must be visible to PHPStan (decision 32).

**Sidebar.** The "Content" group lists every type the actor may view, each followed by its taxonomies the
actor may manage (indented), then Media.

**List.**
- Tabs All, Published, Drafts, Scheduled, Trash with counts (one grouped query).
- Search by title; a language select (remembered in the session; hidden with one language); a term filter
  for types with taxonomies; "Missing translation in: <language>".
- Columns: title with thumbnail and address, terms, translations, author, date, the "⋮" menu
  (decision 35). Sortable by title and date. `flux:table` with pagination.
- A hierarchical type is ordered by `path`, which is tree order, and indented by depth.
- Translations column: a small bar (published, drafts) and `n/total`; hovering shows the same grid of codes
  as the form. Loaded with one extra query per page of the list.
- Trash tab: restore, delete for good (confirmed), and the day each record will be removed.

**Form.** Two columns; on a phone the right one goes below.
- Left: a card with title, address (prefix shown, slug editable), body, excerpt; a card with the type's
  per-language fields when it has any; a "Search engines" card with a result preview, title and description.
- Right: "Publication" (status, date; a future date schedules); "Language and translations"; "For all
  languages" (the type's taxonomies and shared fields; absent when there are none) with a line saying a
  change applies to every translation; "Image"; parent (hierarchical types) and author; "Move to trash".
- "Language and translations": the record's language, `n of total`, a progress bar, and a grid of codes four
  per row. Green = published, amber = draft or scheduled, dashed with "+" = none. A click opens that record
  or the create form; the hover shows its title, state and date. Hidden on a site with one language.
- Categories are a checkbox tree; tags are an input with suggestions.
- A term the group has, or could have, but which has no name in the record's language is still shown, under
  its name in the default language (else the first it has) with a muted "no <language> name" mark, so an
  editor of any translation sees and can untick everything the group is in. The public site never shows it.
- Tag suggestions search the names in **every** language and show each tag once, so typing a word that
  exists only in another language offers the existing tag instead of making a twin. A typed name that
  matches no tag (case-insensitive, any language) creates a new tag named in the record's language on save.
  Joining two tags that turned out to be one is not built.
- Author: a new record's author is the actor. The field lists the users who may create that type (names
  only) and anyone with the type's `edit` may change it.
- An unsaved form warns before leaving. Saving redirects with a toast (decision 8).
- With `view` but not `edit`, the form is read-only (as decision 31 does for roles).

**Taxonomy pages.** A list (tree with indent for categories; name, slug in the list's language, number of
records). A term's form is **one page for all languages**: parent, then a row per site language with name
and slug (slug generated from the name) and an expandable description. A term with no name in a language
does not exist there: no public page, not shown on that language's records.

**Trash rules.** Trashing a page trashes its descendants (the confirmation states how many); restoring it
restores those trashed with it. A record whose parent is gone is restored at the top level. `content:prune-trash`
runs daily and the list page prunes too, so no cron is required (as media does). Deleting for good removes
the record's redirects and media usages, and the group with its term links when it was the last record; if
it was the home page, the setting is cleared.

**Permissions.** Each type registers `<type>.view|create|edit|delete`, each taxonomy `<taxonomy>.manage`,
through `PermissionRegistry`, with labels in both languages. Publishing and restoring need `edit`; trashing
and deleting for good need `delete`. Creating a tag from a record's form needs only the type's `edit`. The
`editor` role's defaults gain all of them; `admin` has `*`. Actions follow decision 25
(`App\Actions\Content\*`, `handle(User $actor, …)`).

## Public side

Plain controllers and Blade, no Livewire. A neutral layout (site logo or name, language switcher) and
templates for a record, a type's list and a term's list, in a folder of their own so a theme can replace
them.

- Head: title (`seo_title`, else title and site name), description (`seo_description`, else excerpt, else
  the start of the body), canonical, Open Graph with the image, `hreflang` for the translations that are
  public plus `x-default`.
- The language switcher links to the translation when it is public, else to that language's home.
  `ContentLocales::alternates()` rebuilds the current route with the same parameters in every active
  language, which is wrong for a record (slugs differ, and a translation may not be public). Content pages
  therefore hand their own alternates to the `hreflang` partial and the switcher; the home page keeps the
  existing behaviour unless a home page is chosen.
- `/sitemap.xml`: public records of every active language, the lists and the terms that hold public
  records, with last-modified dates; read with a cursor; `Cache-Control: public, max-age=3600`. One file;
  splitting past 50,000 addresses is not built.
- Home without a chosen page, or in a language where the chosen page is not public: the current built-in
  welcome page.

**Cost per public page view** (no cache layer in this stage): the record by an indexed lookup, its
translations for `hreflang` and the switcher, its terms, its image, and the body's images: four to five
indexed queries. A list page: one paginated query, one for images, one for terms.

## Languages

- The languages spec left one rule to this stage: **a content language that has records cannot be removed**
  (`RemoveContentLocale` refuses and says how many; records in the trash count, term names do not and
  are simply kept). Switching it off is how it is hidden.
- The translations card and the list count every language added to the site, including ones switched off
  (marked as off), so content can be prepared before a language goes live.

## Parts

1. Registry of types and fields, entries with translations, the editor and sanitizer, the `pages` type in
   the admin and on the site (with the language switcher and `hreflang` for translations), old paths
   recorded **and** answered with 301 (paths change from the first day), trash, the language removal rule.
2. Taxonomies and the `posts` type: categories, tags, type and term lists.
3. The remaining head tags (description, canonical, Open Graph), `sitemap.xml`, the home page setting.

Each part ends with the suite green, an independent review, and the user's browser check.

## Documentation

A new spec `docs/specs/content.md` linked from CLAUDE.md; `languages.md` (removal rule, alternates),
`site-settings.md` (home page), `media.md` (usages by content), `roles.md` (new permission groups); new
decisions in CLAUDE.md and the two dependencies in its stack table.

## Tests

Feature tests per part: the registry and each field kind (through a test-only type); create, update,
translate, publish, schedule, trash, restore, prune; path building, descendants' paths, redirects, reserved
slugs and bases; public visibility by status, date, language and viewer; the sanitizer's allowlist and the
image node; media usages and file deletion; terms shared across translations and per-language term pages;
permissions of every action; the language removal rule; the sitemap; query counts of a public record and a
list. The editor itself and the tag input run in the browser only: they are covered by the user's check and
the spec says so.

## Left to the builder

Class and file names beyond those given; the exact Tiptap packages and toolbar; the sanitizer's precise
configuration; how the wildcard route is ordered last and how alternates reach `ContentLocales`; the
layout of field partials; names of translation keys; the bases of the kit's types and taxonomies per
language (suggested: `blog`, `category`, `tag`; Ukrainian `blog`, `rubryka`, `teg`).

## Known limitations (accepted)

- Two people saving the same record: the later save wins.
- A base added in code for a language can shadow an existing page with that slug; the type wins.
- A published child of a draft parent is public at its address.
- Changing the default language changes every address; those are not redirected (as today).
- A gallery cannot be reordered after picking; two tags created separately for one concept cannot be merged.
- Slugs are ASCII; a title in a script `Str::slug` cannot transliterate needs a slug typed by hand.

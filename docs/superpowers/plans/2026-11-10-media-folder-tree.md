# Media folder tree: collapse, search, move folders, subfolder tiles

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development. Steps use checkbox (`- [ ]`) syntax.

**Goal:** A folder tree that stays readable with many nested folders, folders that can be moved into other folders, and subfolders shown inside the open folder.

**Architecture:** A new `MoveFolder` action (same shape as `RenameFolder`) and page methods on `pages::admin.media.index`. The tree stays server-rendered (`folders()` computed, rows of folder + depth); collapse, indentation cap and folder search are Alpine state in `partials/admin/media/folder-tree.blade.php`. Subfolder tiles are a new partial rendered above the files in grid and list.

**Tech Stack:** Laravel 13, Livewire 4 SFC, Flux 2.21 free, Alpine, Pest 5.

**Spec:** `docs/specs/media.md` (folder tree section, actions table). Approved by the user in chat 2026-11-10 (points 1–5).

## Global Constraints

- Every new UI string goes into `lang/en/media.php` and `lang/uk/media.php` in the same commit (TranslationParityTest).
- Only Flux free components. Destructive or moving actions need `media.edit` (`manageFolders` ability, same as rename).
- `docs/specs/media.md` is updated in the same commit as the behavior it describes; the stale line "a folder can't move into itself or its descendant" in the actions table becomes true and gets its own `MoveFolder` row.
- Run `vendor/bin/pint --dirty --format agent`, `composer types:check`, and the affected tests before each commit. Commit messages end with `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`.

## Review Focus

1. Dropping a folder onto itself, its own child, or its current parent: nothing breaks; self/descendant gives a danger toast, current parent is a no-op.
2. Moving a folder into a place that already has a sibling with the same name: danger toast with the name error, nothing moves.
3. Drag type confusion: file drags (`application/x-media-ids`) must keep working on every target; folder drags (`application/x-media-folder`) must not be accepted by "No folder" and must not start the upload overlay or the marquee.
4. Collapse state in `localStorage` holding ids of deleted folders, or storage throwing (private mode): the tree still renders, the path to the open folder is always expanded.
5. Folder search with Cyrillic and mixed case ("Фотки" / "фот"): matches with `toLocaleLowerCase()`, shows ancestors of matches, clearing restores the collapse state.

---

### Task 1: MoveFolder action and page methods

**Files:**
- Create: `app/Actions/Media/MoveFolder.php`, `tests/Feature/Actions/Media/MoveFolderTest.php`
- Modify: `resources/views/pages/admin/media/⚡index.blade.php` (methods + "Move to…" dialog state), `resources/views/partials/admin/media/dialogs.blade.php` (move-folder dialog), the folder "⋮" menu (next to the breadcrumb), `lang/{en,uk}/media.php`, `docs/specs/media.md`, page tests under `tests/Feature/Admin/Media/`

**Interfaces — Produces:**
- `MoveFolder::handle(User $actor, MediaFolder $folder, ?MediaFolder $target): MediaFolder` — `$target` null = top level. Authorizes `manageFolders` on `Media::class`. Throws `ValidationException` on key `folder` with `media.errors.folder_into_itself` when `$target` is the folder or one of its descendants (`descendantIds()`). Reuses `FolderNameValidator::validate($folder->name, $target?->id, $folder->id)` for the sibling-name check (its `name` error). Moving to the current parent is a no-op returning the folder unchanged.
- Page: `moveFolder(int $id, ?int $targetId): void` — missing folder or missing target → silently refreshes (same as `moveTo`); `ValidationException` → danger toast with the first message; success → `Flux::toast` `media.messages.folder_moved`, unsets `folders`, `breadcrumb`, `currentFolder` computed caches.
- Page: `openMoveFolder(int $id)` / `moveFolderConfirmed()` with `#[Locked] ?int $movingFolderId`, `string $moveFolderTarget` ('' = top level), `bool $showMoveFolderModal`; `cancelPending()` also clears `movingFolderId`. The dialog's native `flux:select` lists "Top level" plus every folder from `folders()` (indented with the depth, prefix with non-breaking spaces) except the moving folder and its descendants.
- The folder "⋮" menu gets "Move to…" (`media.folders.move`) between rename and delete, with `media.edit`.

**Tests (Pest), at least:**
- moves a folder into another folder and to the top level; files inside keep their `folder_id`.
- refuses moving into itself and into a descendant (error key `folder`), nothing changes.
- refuses when the target already has a sibling with the same name (error key `name`).
- requires `media.edit` (403 / `AuthorizationException` for a `media.view`-only user).
- page: `moveFolder` success toast and tree order updated; `moveFolder` into descendant shows a danger toast; the dialog does not list the folder itself or its descendants.

- [ ] Write failing tests, implement, pass, pint + phpstan, update spec, commit "Move folders into other folders".

### Task 2: Collapsible tree, capped indent, folder search, drag folders

**Files:**
- Modify: `resources/views/partials/admin/media/folder-tree.blade.php`, `resources/views/pages/admin/media/⚡index.blade.php` (only if the tree needs more data, e.g. `parent_id` and child flag per row, ancestor ids of the open folder), `lang/{en,uk}/media.php`, `docs/specs/media.md`, a page test.

**Behavior:**
- Each row knows its `parent_id` and whether it has children. A row with children gets a chevron button (`aria-expanded`, `aria-label` `media.index.folders.expand` / `collapse`) before the folder icon; a row without children gets an equal-width spacer so names line up. Clicking the chevron toggles without opening the folder.
- Default: every folder collapsed except the ancestors of the open folder (which are always expanded, even if stored state says otherwise). Toggled state is kept in `localStorage` key `media.folders.expanded` (array of ids) wrapped in try/catch; unknown ids are ignored. A row is shown when all its ancestors are expanded. Use `x-show` (all rows still server-rendered).
- Indent: `0.625rem + min(depth, 4) * 0.75rem`. Full name in a `title` attribute on the name span.
- Search: when there are more than 15 folders, a small `flux:input` (`media.index.folders.search`, icon magnifying-glass, clearable) above the list. While it has text: show folders whose name contains it (`toLocaleLowerCase()` both sides) plus all their ancestors, ignore collapse state, show `media.index.folders.no_match` when nothing matches. Clearing returns to the collapse state.
- Drag folders: folder rows are `draggable="true"` with `media.edit`; dragstart sets `application/x-media-folder` = the folder id and `effectAllowed = 'move'`. Folder rows accept both file drags (existing `moveTo`) and folder drags (`$wire.moveFolder(id, targetId)`); "All files" accepts folder drags (target null = top level); "No folder" accepts only file drags. A folder is not highlighted as a target for itself. Make sure the page-wide upload overlay (media-uploader.js) and the marquee ignore folder drags (they should only react to `Files`); fix there if they don't.
- Hovering a collapsed folder with a drag for 700 ms expands it (nice to have; keep if simple).
- Update the `move_hint` text to mention folders too.

**Tests:** a page test that the tree renders the chevron for parents, the `title` attribute, and `draggable` only with `media.edit`; Alpine behavior itself is checked in the browser by the user.

- [ ] Implement, pass, pint, update spec, commit "Collapse and search the folder tree, drag folders".

### Task 3: Subfolder tiles in the open folder

**Files:**
- Create: `resources/views/partials/admin/media/subfolders.blade.php`
- Modify: `⚡index.blade.php` (computed `subfolders()`), `grid.blade.php` / `list.blade.php` or the index view where they are included, `lang/{en,uk}/media.php`, `docs/specs/media.md`, page tests.

**Behavior:**
- Computed `subfolders(): Collection<MediaFolder>` — direct children of the open folder ordered by name, with `media_count` and `children_count`. Empty for "All files" and "No folder" and while any of search/type/month/unused filters is active (results are files then).
- Rendered above the files in both grid and list view as a row of compact tiles (`grid` of folder cards: folder icon, name truncated with `title`, "N files · M folders" using trans_choice keys `media.index.subfolders.files` / `folders`). Click opens the folder (`$set('folder', id)`). Keyboard: tiles are buttons.
- With `media.edit`: tiles are drop targets for file drags (`moveTo`) and folder drags (`moveFolder`), and are draggable as folders (same `application/x-media-folder` payload as the tree). Reuse the tree's drag helpers — extract them into `resources/js/media-folder-drag.js` (registered as an Alpine data or plain functions on `window` like the existing drag helper) instead of copying.
- Tiles are not part of selection, Shift/Ctrl click, or the marquee (marquee must not start on a folder tile and must not count it).
- The empty state of a folder that has subfolders but no files must not say the folder is empty; show the tiles and no empty-state block (or a lighter "No files here" line).

**Tests:** subfolders computed for a folder; hidden for all/none and with a filter; tile renders counts; empty-state not shown when subfolders exist.

- [ ] Implement, pass, pint, update spec, commit "Show subfolders in the open folder".

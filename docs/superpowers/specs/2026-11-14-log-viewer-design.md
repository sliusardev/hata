# Log viewer on the maintenance page: design

Status: approved by the user and built 2026-11-14 on the branch `log-viewer`. Differences from this text: the
first chunk is fetched by the viewer too (nothing of the log is rendered with the page), and the page's
actions take a key of the file (an md5 of its name) instead of the name. The current description is in
`docs/specs/maintenance.md`.

## Intent

The owner wants to read Laravel's error logs from the admin instead of opening the files over SSH or FTP, on
sites where a console is inconvenient. The request, in the user's words: open the logs as they are in a
good-looking viewer with highlighting, see every file of the logs folder, view and delete. It must keep
working when a site switches from the `single` channel to `daily`.

## Goals

- A **Logs** tab on `/admin/maintenance` (third tab, after Actions and Journal).
- Lists every file of `storage/logs`, whatever channel wrote it.
- Opens a file as it is: the raw text, in file order, with highlighting.
- Deletes a file after a confirmation.
- No new dependency.

## Non-goals

Parsing the log into entries, filters by level, search, download, live tail or auto-refresh, clearing a file
without deleting it, editing, subfolders of `storage/logs`, log files outside `storage/logs`, changing the
logging channel from the admin.

## Anti-goals

- A big log must never slow a request or the browser down: no request reads a whole file, and no file content
  travels in Livewire's component state.
- The viewer must never read or delete a file outside `storage/logs`.
- Nothing runs outside this tab (see Performance below).

## Approaches

1. **Own reader that parses entries** (level filter, search, collapsible stack traces). Most comfortable for
   hunting one error in a noisy file, and the most code: a backwards reader plus an entry parser with its edge
   cases. Not picked: the user wants the file as it is.
2. **The raw file in a viewer with highlighting** — picked by the user. The file is shown as text, read in
   chunks from the end; highlighting is decided per line, so nothing has to understand where an entry starts
   or ends. About half the work of 1.
3. **A package (`opcodesio/log-viewer`)**. Ready-made, but a dependency with its own UI, routes and
   authorization. Rejected: the user asked for no package.

Writing errors to the database was dropped early: a query per log line, and no log when the database is down.

## Decided by the user

- Laravel's log files, read from the files themselves.
- All files of the logs folder, so `single`, `daily` and custom channels work without settings.
- Raw content with highlighting, not a parsed list.
- View and delete; nothing else.
- No package.

## Design (the builder's calls unless marked above)

### Access

The tab lives on the maintenance page, so it is behind `maintenance.run` (reserved, super admin by default).
Logs can hold personal data and fragments of requests. The chunk route and the delete action authorize the
same permission themselves.

### Files

`App\Support\Maintenance\LogFiles`:

- `all()`: the regular files directly in `storage/logs` whose name does not start with a dot (so `.gitignore`
  is skipped), with name, size and modification time, newest first. No recursion.
- `resolve(string $name)`: returns the path only when `$name` equals the name of a file `all()` lists;
  anything else (a path, `..`, a dot file, a symlink leaving the folder) is refused. Every read and delete
  goes through it.

### Screen

The tab has two states, switched by `#[Url] file`:

```
Maintenance:  Actions | Journal | Logs

 File                        Size      Changed
 laravel-2026-11-14.log      1.2 MB    14 Nov 2026, 15:32     ⋮  Open / Delete
 laravel-2026-11-13.log      340 KB    13 Nov 2026, 23:58     ⋮
 browser.log                 1.1 MB    14 Nov 2026, 15:30     ⋮
```

```
 ← All files     laravel-2026-11-14.log · 1.2 MB · changed 15:32            ⋮  Delete
 ┌───────────────────────────────────────────────────────────────────────────┐
 │                       [ Load earlier · 960 KB above ]                     │
 │ [2026-11-14 15:28:02] production.ERROR: Call to undefined method …        │
 │ [stacktrace]                                                              │
 │ #0 app/Actions/Media/UploadMedia.php(84): …                               │
 │ [2026-11-14 15:32:07] production.WARNING: Media probe failed …            │
 └───────────────────────────────────────────────────────────────────────────┘
```

- The list is a `flux:table` in a card; the file name links to the viewer; per-row actions are in the "⋮" menu
  (decision 35). An empty folder shows `<x-admin.empty-state>`.
- The viewer is a full-width dark monospace panel with its own scroll, opened scrolled to the bottom (the
  newest lines). Long lines wrap. It follows the color scheme tokens where it can.
- A file that no longer exists (deleted meanwhile, rotated) returns to the list with a danger toast.

### Reading

`App\Support\Maintenance\LogReader::chunk(string $path, ?int $before): array{html, start, end, size}`:

- Reads at most 256 KB ending at `$before` (the file's end when null), moved forward to the first line start
  so a chunk never begins in the middle of a line (unless that single line is longer than the chunk).
- Makes the text valid UTF-8 and escapes it before any markup is added.
- `GET admin/maintenance/logs/{file}` (`admin.maintenance.logs.chunk`, a plain controller, admin group plus
  `can:maintenance.run`) returns one chunk as JSON. The first chunk is rendered with the page; "Load earlier"
  fetches the previous one with Alpine and prepends it, keeping the scroll position. The content is rendered
  into the page, never held in a public property, so it is not part of the Livewire snapshot.

### Highlighting

Server-side, per line, no JavaScript library:

- A line that starts an entry (`[date time] env.LEVEL:`): the timestamp muted, the level colored
  (emergency, alert, critical, error: red; warning: amber; notice, info: blue; debug: grey).
- Stack frames (`#12 …`) and the `[stacktrace]` / `[previous exception]` markers: dimmed.
- Every other line: plain.

Files that are not Laravel logs (for example `browser.log`) simply show mostly plain lines.

### Deleting

`App\Actions\Maintenance\DeleteLogFile::handle(User $actor, string $name)`: authorizes `maintenance.run`,
resolves the name, deletes the file. Called from a Livewire action behind `<x-confirm-modal>` (unlike the
cache commands, deleting a file touches nothing Livewire depends on); success and failure are toasts. Deleting
the file a channel is writing to is fine: Laravel creates it again on the next write. Deletions are not written
to the maintenance journal, which records command runs.

### Performance

Nothing outside this tab. Opening the tab lists one directory. Opening a file reads at most 256 KB, whatever
the file's size; each "Load earlier" reads 256 KB more. No queries beyond the page's own authorization.

### Tests

Access (page tab, chunk route, delete, without the permission and as a guest); the list (dot files and
subfolders skipped, order); tampered names on the chunk route and on delete (`../.env`, absolute paths, dot
files); chunk boundaries on a real file (line alignment, the last chunk, a line longer than a chunk, an empty
file); invalid UTF-8; markup in a log line stays escaped; level and stack-frame classes; delete and the
file-gone case; the other tabs do not list the directory.

### Docs

`docs/specs/maintenance.md` gets the Logs tab; CLAUDE.md gets a decision; translations in `lang/en` and
`lang/uk`.

## Left to the builder

Exact colors and spacing of the panel, the wording of labels, the JSON shape of the chunk response.

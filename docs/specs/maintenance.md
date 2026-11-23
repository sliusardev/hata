# Maintenance page

> The admin address is `config('hata.admin.path')` (env `HATA_ADMIN_PATH`, default `admin`); `/admin` in this spec stands for it. Route names stay `admin.*`.

Service commands for after a deploy, run from the admin instead of a console, and the log files. Designs:
`docs/superpowers/specs/2026-11-12-maintenance-page-design.md` (decision 54 in CLAUDE.md) and
`docs/superpowers/specs/2026-11-14-log-viewer-design.md` (decision 55).

## Why

The kit is used for many sites, some on SSH hosting where opening a console is inconvenient. After a deploy the
owner needs a few commands: run the new migrations, run one seeder, clear or rebuild the caches, and wants to
see what the command printed and who ran what.

## Page

- `GET /admin/maintenance` (`admin.maintenance`, Livewire SFC `pages::admin.maintenance.index`), admin group
  plus `can:maintenance.run`; `mount()` authorizes again. Sidebar item "Maintenance" under Administration,
  after "Site settings", shown with the permission.
- Tabs like Site settings (`flux:navbar`; `#[Url] tab`, `TABS` in the page; an unknown value falls back to `actions`): **Actions**, **Journal** and **Logs**. Each tab loads only its own data: the Actions tab never queries the journal, the Journal tab never reads the migration state, and only the Logs tab lists the logs folder. The user asked for tabs because database tools will be added later as another tab.
- Actions tab: four cards, each with what the action does:
  - **Run migrations**: the names `migrate` would run now (`PendingMigrations::names()`, read through the
    migrator: migration files minus the ran ones), with their count. With nothing pending it says so and has
    no button.
  - **Run a seeder**: a select of `SeederCatalog::names()` and a button.
  - **Clear caches** and **Rebuild caches**.
- Every button opens a confirmation dialog (`confirm($action)`, `#[Locked] confirming` and `confirmedSeeder`),
  which names what will run (the number of migrations, the seeder). "Rebuild caches" adds a warning in the
  local environment: cached config and routes hide later `.env` and route edits. The dialog is a real form:
  its button posts `action` and `seeder` to `POST /admin/maintenance/run` (below) and disables itself while
  the request runs. It is not `<x-confirm-modal>`, whose button calls a Livewire action.
- **Last result** (Actions tab): the run made just before this page load: status badge, action, duration, output in a monospace block, and a link to the journal.
- **Journal** tab: `flux:table`, 15 per page, newest first: when (site timezone), who, action (with the seeder),
  status, duration; the row's "⋮" menu (and the action's name) opens the output in a modal (`showOutput()`).
  The list query selects `MaintenanceRun::LIST_COLUMNS`, never the output; the output is loaded for the one
  entry that is opened.

## Permission

- `maintenance.run` (group `maintenance` in `config/internal/permissions.php`). It is **reserved**
  (`hata.permissions.reserved`): the `*` of the `admin` role's defaults does not grant it (see the roles spec), so
  by default only a super admin has it (through `Gate::before`). A super admin can tick it for a role in the
  matrix; an admin cannot (rule 5: you grant only what you have).

## Actions

`App\Enums\MaintenanceAction` is the whole list; nothing else is ever passed to Artisan.

| Action | Command |
|---|---|
| `migrate` | `migrate --force` |
| `seed` | `db:seed --class={full class} --force` |
| `clear_caches` | `optimize:clear` |
| `rebuild_caches` | `optimize` |

`App\Actions\Maintenance\RunMaintenanceAction::handle(User $actor, MaintenanceAction $action, ?string $seeder)`:

1. Authorizes `maintenance.run`.
2. For `seed`, resolves the name through `SeederCatalog::resolve()`; a name that is not on the list (a full
   class name, a path, another class, extra options) throws `MaintenanceException::unknownSeeder()` and
   nothing runs.
3. For `clear_caches`, refuses while a media batch (optimize or move) is running
   (`MaintenanceException::mediaBatchRunning()`): those hold cache locks, which `cache:clear` wipes on the
   `file` and `redis` stores.
4. Takes the run lock by creating the journal entry (below); another run in progress throws
   `MaintenanceException::busy()`.
5. (Without the journal table only `migrate` gets this far, see "First deploy".) Lifts the time limit where the host allows, runs the command through
   `App\Support\Maintenance\ArtisanRunner` (the one place that calls Artisan; buffered, undecorated output)
   and finishes the entry: exit code 0 is `succeeded`, anything else `failed`; a command that throws is
   `failed` with the exception's class and message as output, and is reported to the log.
6. Deletes journal entries beyond the latest 200 (`KEEP`).

## Running: a plain request, never a Livewire one

`POST /admin/maintenance/run` (`admin.maintenance.run`, `Admin\MaintenanceRunController`, admin group plus
`can:maintenance.run`) validates `action` against the enum, calls the action and redirects to the page. A
refusal (`MaintenanceException`) becomes a danger toast; a finished run becomes a success or danger toast
(`App\Support\Toast`) and its id is flashed (`LAST_RUN_KEY`), which the page's `mount()` reads for "Last
result".

Why not a Livewire action (both were hit in the browser while building this):

- `optimize:clear` and `optimize` delete the compiled views, among them the compiled view of the component
  that is about to render: a 500.
- `optimize` (`config:cache`, `route:cache`) boots a second application inside the process. That resets
  Livewire's registry of the components alive in the request, so the response loses its redirect and its
  state (the next click then failed with `Undefined array key "children"`).

A controller that only redirects depends on neither. `ArtisanRunner` also puts back what that second
application re-points: the container instance, the facades, Eloquent's connection resolver and event
dispatcher. A test runs the real `optimize` through the route, with every cache path pointed at a scratch
folder.

**First deploy**: `maintenance_runs` is created by a migration of this feature. Until it exists the page still
opens (the journal is empty), "Run migrations" runs without the lock and writes its entry afterwards, and
every other action is refused with "run the migrations first".

## Journal and lock (`maintenance_runs`)

- Columns: `user_id` (nullable, set null when the user is deleted), `user_name` (kept as text, so an entry
  stays readable), `action`, `parameter` (the seeder's name), `status` (`running`, `succeeded`, `failed`,
  `interrupted`), `slot`, `exit_code`, `output`, `output_truncated`, `started_at` (indexed), `finished_at`.
- **Lock**: `slot` is unique and holds `run` only while a run is in progress, so the database refuses a
  second running entry. It is a row, not a cache lock, because "Clear caches" empties the cache store on some
  drivers. Finishing sets it to null.
- **Dead runs**: a shutdown function marks a run `interrupted` when the process dies on a fatal error (time
  or memory limit). If even that did not run, the entry stops blocking after 30 minutes (`STALE_MINUTES`):
  the next run releases it and marks it `interrupted`, and the page already shows it so (`shownStatus()`). A
  run that really takes longer than that loses its lock; accepted.
- **Output**: made valid UTF-8 (a strict database refuses anything else) and trimmed; over 64 KB (`OUTPUT_LIMIT`) only the end is kept and `output_truncated` is set.

## Seeders

`SeederCatalog::names()`: every concrete class under `database/seeders` (subfolders too) that extends
`Illuminate\Database\Seeder`, by its name relative to `Database\Seeders`, sorted, `DatabaseSeeder` first.
Seeders run through `db:seed --class`, so the container builds them.

## Logs tab

The owner reads Laravel's logs here instead of opening the files over SSH or FTP. The user asked for the files
as they are, in a viewer with highlighting, every file of the folder, view and delete; no package.

- **Files** (`App\Support\Maintenance\LogFiles`): the regular files directly in `storage/logs`, newest first,
  so `single`, `daily` and custom channels need no setting. Dot files, subfolders and symbolic links are left
  out. `resolve($name)` returns a path only for a name the listing holds; every read and delete goes through
  it, so nothing outside the folder can be read or deleted. The page's actions take the file's `key` (an md5
  of the name), so a file name is never written into a script.
- **List**: `flux:table` with name, size and change time (site timezone); the name opens the viewer, the "⋮"
  menu has Open and Delete. No files: an empty state.
- **Viewer** (`#[Url] file`, a listed name; anything else falls back to the list with a danger toast; leaving
  the tab closes it): a dark monospace panel that shows the file in file order, opened scrolled to the end,
  where the newest lines are. The content is **not** rendered by Livewire: the panel (`wire:ignore`) fetches
  it from `GET admin/maintenance/logs/{key}?before={offset}` (the file's key, not its name: a name may hold
  `#`, `?` or `%`, and a web server may refuse a path ending in `.log`) (`admin.maintenance.logs.chunk`,
  `Admin\MaintenanceLogChunkController`, admin group plus `can:maintenance.run`, `no-store`), so a log never
  travels in the component's responses. "Load earlier lines" fetches the chunk above and keeps the scroll
  position.
- **Reading** (`App\Support\Maintenance\LogReader::chunk($path, $before)`): at most 256 KB (`CHUNK`) ending at
  `$before` (the end of the file when null), moved to the start of a line unless that line alone is longer
  than a chunk. Returns `html`, `start` (pass it as the next `before`; 0 is the top), `end`, `size`; the
  route adds `remaining` (the size above, formatted). The text is made valid UTF-8 and escaped before any
  markup is added.
- **Highlighting**, per line, on the server, no JavaScript library: an entry line
  (`[date] env.LEVEL:`) gets `log-date` and `log-level log-level-{level}`; stack frames (`#12 …`),
  `[stacktrace]` and `[previous exception]` get `log-trace`. The colors are in `resources/css/app.css`
  (`.log-text`). Nothing is parsed into entries, so files that are not Laravel logs show as plain text.
- **Deleting** (`App\Actions\Maintenance\DeleteLogFile::handle(User $actor, string $name)`): authorizes
  `maintenance.run`, resolves the name, deletes the file; an unlisted name or a failed delete throws
  `MaintenanceException`. Called from a Livewire action behind `<x-confirm-modal>` (deleting a file touches
  nothing Livewire depends on). A channel that was writing to the file creates it again on its next write; a
  long-running process (a queue worker) keeps its handle and writes to the deleted file until it restarts,
  which the confirmation says. A file that could not be deleted stays open in the viewer; one that is gone
  returns the page to the list. Deletions are not written to the journal, which records command runs.

## Config cache and stored settings

`config:cache` boots a fresh application to collect the config. `AppServiceProvider::boot()` applies the
stored site settings there too, which would freeze them into `bootstrap/cache/config.php`: a setting removed
later would keep its old value, and the decrypted SMTP password and S3 secret would be written to that file.
So `SiteSettings::apply()` does nothing while the config cache is being built
(`SiteSettings::buildingConfigCache()`): `AppServiceProvider` suspends it between the console's
`CommandStarting` and `CommandFinished` events for `config:cache` and `optimize` (the events carry the
resolved command name, so options before the command, abbreviations and `Artisan::call()` are covered), and
"Rebuild caches" wraps its run in `SiteSettings::withoutApplying()` as well. The cache
then holds the `.env`-level values, and the stored settings are applied over them on every request as before.

## Performance

Nothing runs outside this page: no middleware, no provider work, no query elsewhere; the sidebar item is one
permission check. Opening the page costs the pending-migrations check (one query on `migrations` and a
directory listing), the seeder discovery (a directory listing) and one page of the journal without outputs.
The Logs tab lists one directory; opening a file reads at most 256 KB per request, whatever the file's size,
and runs no query of its own.

## Not included

Arbitrary commands or options, rollback, `migrate:fresh`, the storage link (Settings → Media has it),
maintenance mode, queue or scheduler control, a CLI twin. For logs: parsing into entries, filters, search,
download, live tail, subfolders of `storage/logs`, changing the logging channel.

## Tests

`tests/Feature/Admin/Maintenance/MaintenancePageTest.php`: access (no permission on the page and on the run
route, guests, the admin role, a role given the permission, the action itself), the pending list, the
confirmation form and a real `migrate` through the runner, the first deploy without the journal table, seeder
discovery, tampered names and a seeder swapped after confirming, both cache actions, the media-batch refusal,
the config-cache switch (direct and by command events), a real `optimize` through the route into a scratch
folder (redirect, flash, application state, no stored setting in the cache file), the local warning, unknown
actions, failed and throwing commands, invalid UTF-8, the lock and its release, truncation, pruning, the tabs
and the journal with its output modal. Other cache runs go through a fake `ArtisanRunner`.

`tests/Feature/Admin/Maintenance/LogViewerTest.php` (a scratch folder stands in for `storage/logs`): access
to the chunk route and the delete action, the listing (dot files, folders, links, order), tampered names on
resolve, the route and delete, chunk boundaries on real files (line alignment, chunks joining back into the
file, a line longer than a chunk, an empty file, positions out of range), escaping and invalid UTF-8, the
highlight classes, and the page (the tab, the empty state, open/close, the `file` address, delete with its
confirmation, a file gone meanwhile, the folder is not read on other tabs, the content is not in the page).

## Known limitations

- A `migrate` started over SSH at the same moment is not detected.
- With sessions kept in the cache store (`SESSION_DRIVER=cache`, or redis shared with the cache), "Clear
  caches" logs everyone out and the result toast is lost; the kit's default is database sessions.
- `DatabaseSeeder` creates the super admin from `ADMIN_EMAIL`/`ADMIN_PASSWORD` when that user does not exist,
  so a role given `maintenance.run` can bring that account back if those values are still in `.env`.
- A single crop or replace in progress is not checked before "Clear caches"; on the `file` and `redis` cache
  stores its lock could be lost (media batches are checked).
- If "Rebuild caches" leaves the site unable to boot, the page can't help: delete `bootstrap/cache/*.php`
  by hand (README).
- The log viewer's Alpine code (fetching, prepending, scroll position) is covered only by a browser check, not
  by tests.
- A log line longer than 256 KB is split across chunks, and its later part is highlighted as plain text.
- A file replaced or truncated while it is open (rotation) can show a mismatched part after "Load earlier
  lines"; reopening the file fixes it.
- Runs happen inside the request; a host with a hard request time limit can cut a long migration (the entry
  then reads "interrupted").

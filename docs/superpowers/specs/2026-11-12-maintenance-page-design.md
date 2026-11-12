# Maintenance page: run service commands from the admin

Status: built on 2026-11-12; `docs/specs/maintenance.md` is the authoritative description. Differences from this design: the run lock is the unique `slot` column of the journal row; a shutdown function marks a run that died on a fatal error as interrupted; commands run in a plain controller request posted by the confirmation dialog, not in a Livewire action (Livewire does not survive `optimize` in its own request); the page has Actions and Journal tabs; building the config cache never applies the stored site settings (found while building: they would have been frozen into the cache file with decrypted secrets).

## Why

hata is a starter kit for many sites, some on servers and some on SSH hosting. After a deploy the owner often
needs one of a few service commands: run the new migrations, run one seeder, clear the caches. Opening a
console for that is inconvenient, and on some hosting hardly possible. The owner wants buttons in the admin
for these and wants to see what the command printed.

## Goals

- A page with a fixed set of actions, defined in code:
  1. **Run migrations** (forward only).
  2. **Run a seeder** chosen from a list of every seeder the project has.
  3. **Clear the caches.**
  4. **Rebuild the caches.**
- Before running migrations, show which ones are not applied yet; nothing to run means no button to press.
- After a run, show the command's output and whether it succeeded.
- A journal: who ran what, when, how it ended, with the output.
- Only a holder of a separate permission can open the page or run anything; by default that is the super
  admin alone.

## Non-goals

- No field for an arbitrary artisan command, and no way to pass free-form options.
- No rollback, `migrate:fresh`, `migrate:refresh` or `db:wipe`.
- No "Create the storage link" here: Settings → Media already has it.
- No maintenance mode (`down`/`up`), queue or scheduler control. They may come later as more actions.

## Anti-goals (a failure even if it "works")

- Any way to make the server run a command or a class that is not on the fixed list (a tampered request
  naming another command, a class outside the seeders folder, extra options).
- A run that leaves no journal entry, or an entry that claims success for a failed run.
- Extra work on ordinary requests. Everything this feature does happens on its own page and only there.
- Two runs at the same time (two tabs, a double click, two admins).

## Decided with the owner

- Fixed set of buttons, no free input.
- A separate permission, super admin only by default.
- Seeders are discovered automatically; the owner picks one and runs it.
- Two cache buttons: clear, and rebuild.
- The list of pending migrations is shown before the run.
- A journal of runs: who, when, what.
- The storage link stays on the Media tab.

## My calls (the owner has not confirmed these; listed for their review)

1. **A page of its own, not a tab of Site settings.** The owner said "a Maintenance tab in settings, or
   something like that". The settings page is one form with sections, a Save button and an unsaved-changes
   guard, and it is gated by `settings.edit`; maintenance has no form, another permission, and reads the
   migration state when it opens. So: `/admin/maintenance` (`admin.maintenance`), a sidebar item
   "Maintenance" under Administration right after "Site settings", shown only with the permission.
2. **Permission `maintenance.run`**, a new group `maintenance` in `config/permissions.php`. The `admin` role
   is seeded with `*` (every permission), which would hand it this one too. So `*` gets an exclusion list:
   a new config key (e.g. `permissions.reserved`) names permissions that `*` does not grant, and
   `maintenance.run` is the first. Super admins pass through `Gate::before`. A super admin may still grant
   it to a role in the matrix on purpose; an admin can't, because rule 5 only lets you grant what you have.
3. **Every action asks for confirmation** (`<x-confirm-modal>`), naming what will run (the seeder's class,
   the number of pending migrations).
4. **Runs happen inside the request**, not on the queue: the result is needed at once, a queue worker may not
   exist on hosting, and a worker keeps old code in memory after a deploy. The time limit is lifted for the
   run where the host allows it.
5. **One run at a time**; a second attempt gets a message, not a queue. The lock must not live in the cache
   store (the "clear" action empties it on the `file` and `redis` stores): it is kept in the database, next
   to the journal. A "running" entry older than 30 minutes no longer blocks and is shown as interrupted.
6. **Journal size**: the latest 200 runs are kept, older ones are deleted when a new run is recorded. Each
   entry's output is cut at 64 KB (the end is kept, where errors are), and the entry says it was cut.
7. **The journal keeps the runner's name as text** next to the user id, so an entry stays readable after the
   user is deleted.
8. **`DatabaseSeeder` is in the list** like any other seeder (it is the "everything" seeder; in this kit it
   is safe to repeat).
9. **Commands**: migrations `migrate --force`; seeder `db:seed --class=… --force`; clear `optimize:clear`;
   rebuild `optimize`.

10. **Reserved permissions**: the exclusion applies only where the seeder expands `*`. `PermissionRegistry`,
    the matrix and `permissions:sync` keep listing `maintenance.run` like any permission. Nothing has to be
    taken away from existing roles: the permission is new and reserved from its first release.
11. **No CLI twin** of the page and no `--isolated` for `migrate` (it would use the cache lock store). A
    `migrate` started over SSH at the same moment is not detected; that is accepted.

## How it should behave

### The page

- Header: title and one sentence saying these actions change the site for everyone.
- Four blocks, one per action, each with a short explanation of what it does and when to use it:
  - **Migrations**: the list of pending migration names (or "Everything is applied"), and "Run migrations",
    present only when something is pending.
  - **Seeder**: a select with the discovered seeders and "Run seeder". A hint that a seeder may add or
    change data and that this can't be undone from here.
  - **Clear caches**: explains that the site is slower until the caches are rebuilt.
  - **Rebuild caches**: explains that later `.env` or route changes need another rebuild (or a clear).
- **Last result**: after a run, its status (succeeded / failed), duration and output in a monospace block.
- **Journal**: a table, newest first: when (site timezone), who, action (with the seeder's name), status,
  duration, and a "⋮" menu or button to open the entry's output in a modal. Paginated.
- Translations in `lang/en` and `lang/uk`. Command output itself is shown as printed (English).

### Running

- The server accepts only an action key from the fixed list (and, for the seeder, a class name that is in
  the discovered list at that moment). Anything else is refused and not run.
- A journal entry is written before the command starts (status "running"), then finished with the result.
  If the process dies mid-run the entry stays "running"; the page shows such an entry as interrupted once
  the lock is free.
- A command that throws, or returns a non-zero exit code, is a failed run; the exception's message goes to
  the output and the exception is reported to the log.
- After migrations the pending list is refreshed; after either cache action the page still renders (the
  views it needs are compiled again on that same request or the next).

### Seeder discovery

- Every class under `database/seeders` (subfolders too) that extends `Illuminate\Database\Seeder` and can be
  instantiated. Shown by class name relative to `Database\Seeders`, sorted, `DatabaseSeeder` first.

#### Checked against the code (builder check, 2026-11-12)

- **Cache locks vs. clearing the cache.** `optimize:clear` runs `cache:clear`. With the default `database`
  store the locks sit in their own `cache_locks` table and survive; with `file` and `redis` they are wiped.
  Media batches (`MediaBatchEngine`, two modes: optimize and move) and single-file rebuilds
  (`RebuildMediaFile`) hold such locks. Rule: "Clear caches" is refused while either media batch is running
  (ask both engines). A single crop or replace in progress (seconds long) is not checked; on `file`/`redis`
  its lock could be lost, which is recorded as a known limitation.
- **What else the cache holds**: the site settings (`SiteSettings::CACHE_KEY`, re-read from the database on
  the next request) and `media.avif_supported` (recomputed). Nothing breaks when they are cleared.
- **`optimize` and stored settings**: `AppServiceProvider::boot()` applies the stored settings over whatever
  config is loaded, so a cached config freezes only the `.env`-level values. No code calls `env()` outside
  `config/`.
- **Route cache**: two routes are closures (`routes/web.php`, `routes/settings.php`); Laravel serializes
  them, but the builder runs `route:cache` once to confirm before relying on it.
- **Production**: `DB::prohibitDestructiveCommands()` does not cover `migrate` or `db:seed`; both run with
  `--force`.
- **Seeders take dependencies** (`RoleSeeder::run(PermissionRegistry $registry)`), so discovery and running
  go through the container (`db:seed --class`), never `new`.
- **Sidebar**: the Administration group's `@canany` list and the new item both need `maintenance.run`.

### Still for the builder to handle

- **`optimize` in the local environment** makes later `.env` and route edits invisible until cleared. Show
  a stronger warning in the confirmation when `app.env` is `local`.
- **Output of `migrate`/`db:seed` when run from a web request** (no TTY, no ANSI): capture through a buffered
  output and strip any styling.
- **Time limit**: `set_time_limit(0)` only where the function exists and is allowed.
- **Recovery**: if a rebuild leaves the site unable to boot, the page can't help. The README gets the
  manual way out (delete `bootstrap/cache/*.php`).

## Performance

- Nothing in this feature runs outside its page: no middleware, no service-provider work, no query on other
  pages. The sidebar item is one permission check (already in memory).
- Opening the page costs: the pending-migrations check (one query on `migrations` plus a directory listing),
  the seeder discovery (a directory listing), and one page of the journal (one indexed query). The journal
  list does not load the output column; the output is loaded for the one entry that is opened.

## Data

- Table `maintenance_runs`: id, user_id (nullable, set null on delete), user_name, action, parameter
  (nullable: the seeder class), status (running / succeeded / failed), exit_code (nullable), output (long
  text, nullable), output_truncated (bool), started_at, finished_at (nullable). Index on started_at.

## Left to the builder

- Class names and file layout, following the kit's conventions (actions with an actor in `app/Actions`,
  Livewire SFC page, `flux:table`, `<x-admin.row-actions>`, `<x-confirm-modal>`, `App\Support\Toast`).
- How the pending migrations are read (the migrator's own API rather than parsing command output).
- The lock's name and timeout.
- Tests (Pest): permission and tampering cases, each action's happy path and failure path, journal
  pruning and truncation, the lock, the pending list, the seeder discovery, `*` not granting the reserved
  permission, translations parity.
- Docs in the same change: a new `docs/specs/maintenance.md` linked from CLAUDE.md, a decision entry in
  CLAUDE.md, the roles spec (reserved permissions), the README (the page and the recovery note).

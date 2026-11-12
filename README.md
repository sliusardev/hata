# Admin panel starter kit

A Laravel admin panel starter kit with users, roles and permissions, built on the official Laravel Livewire starter kit. Stage 1 covers the foundation: an English/Ukrainian interface, code-defined permissions, roles, and user and role management. Pages, posts, media and frontend themes come in later stages.

## Stack

- Laravel 13, PHP 8.4+ with the `intl` extension (file sizes and numbers are formatted with it)
- Livewire 4 (single-file components), Flux UI (free), Tailwind CSS 4
- Laravel Fortify for authentication (login, password reset, 2FA, passkeys)
- spatie/laravel-permission for roles and permissions
- Pest for tests
- MySQL/MariaDB or SQLite

Production needs neither Node.js nor long-running workers, so the kit runs on shared hosting as well as on a VPS.

## Installation

```bash
git clone <repository-url> admin && cd admin
composer install
cp .env.example .env
php artisan key:generate
```

Configure the database in `.env` (SQLite works out of the box with `DB_CONNECTION=sqlite`), then:

```bash
php artisan migrate
php artisan storage:link # lets the web server serve the uploaded images directly
php artisan db:seed      # creates the permissions and the system roles
php artisan admin:create # creates the first super admin
npm install && npm run build
```

`db:seed` is safe to run on every deploy. It only creates missing permissions, roles and the initial admin, and it never removes permissions that an administrator assigned.

### Shared hosting without SSH

1. Build the frontend locally (`npm run build`) and upload `public/build` together with the code.
2. Set `ADMIN_NAME`, `ADMIN_EMAIL` and `ADMIN_PASSWORD` in `.env`.
3. Run the migrations and the seeder from your hosting control panel (`php artisan migrate --force && php artisan db:seed --force`). The seeder creates that super admin if it does not exist yet.
4. Create the storage link (`php artisan storage:link`) if the panel can run it. Otherwise open Admin → Site settings → Media and press "Create the storage link"; images work without it, only more slowly.
5. Remove `ADMIN_PASSWORD` from `.env` after the first deploy.

No job needs a worker. On hosting without workers, set `QUEUE_CONNECTION=sync`: long media tasks (optimizing or moving existing files) then run in the open admin page, or with `php artisan media:optimize` / `media:move-disk` over SSH. With a real queue, run a worker and use one shared cache store (database, redis or file, not `array`) because media jobs use cache locks.

## Creating the first admin

```bash
php artisan admin:create
```

The command asks for the name, email and password. To run it without prompts, pass the values as options:

```bash
php artisan admin:create --name="Jane Doe" --email=jane@example.com --password="a-strong-password"
```

The new user gets the `super-admin` role, which passes every permission check.

## Roles and permissions

- **Permissions** are declared in code and can only be assigned to roles from the interface.
- **System roles** are created by the seeder: `super-admin`, `admin`, `editor` and `author`. They cannot be deleted, and `super-admin` cannot be changed at all.
- **Code checks permissions, never role names:** `can('users.edit')`, `@can('roles.view')`. The only exception is super-admin, which passes every check through `Gate::before`.
- **Protection rules** prevent removing the last super admin, changing your own roles, deleting yourself and escalating privileges. They are enforced in the actions in `app/Actions`, not only in the interface.

### Adding a permission

1. Declare it in `config/permissions.php` under its resource group (format `resource.action`):

    ```php
    'groups' => [
        'pages' => ['view', 'create', 'edit', 'delete'],
    ],
    ```

    A module can register its permissions from a service provider instead:

    ```php
    $this->app->make(\App\Support\Permissions\PermissionRegistry::class)
        ->register('pages', ['view', 'create', 'edit', 'delete']);
    ```

2. Add the labels to every language in `lang/*/permissions.php` (`groups.pages`, `items.pages.view`, …).
3. Optionally grant it to a system role by default in `config/permissions.php` → `roles`.
4. Run `php artisan permissions:sync` (or `php artisan db:seed`). Add `--prune` to delete permissions that were removed from the registry.

## Languages

The interface is available in English (`en`, the default and fallback) and Ukrainian (`uk`). Each user chooses a language under **Settings → Language**. Guests see `APP_LOCALE`.

### Adding a language

1. Add it to `config/app.php` → `available_locales`, for example `'pl' => 'Polski'`.
2. Copy `lang/en` to `lang/pl` and translate every file. Use PHP files only, not JSON.

`tests/Feature/Localization/TranslationParityTest.php` fails if a file or key is missing, a value is empty, or placeholders differ from English. It also fails if the code uses a translation key that does not exist.

## Public registration

Registration is disabled by default. Turn it on in **Admin → Site settings** (or set `ADMIN_REGISTRATION_ENABLED=true`). Registered users get the role chosen there, no role by default, so they cannot open the admin panel until an administrator assigns one.

The site settings page also holds the site name, default language, timezone, email (SMTP) settings the light/dark logo (PNG, JPG, WebP or SVG, picked from the media library) and the color scheme of the admin panel (nine presets or your company color). Values saved there override `.env`. After updating an existing install, run `php artisan migrate`, `php artisan permissions:sync` and `php artisan db:seed --class=RoleSeeder` so the `admin` role gets the new `settings.edit` permission.

## Media library

Admin → Media stores raster images (and their thumbnails) on Laravel's `public` disk and serves them as static files, so PHP does not run for them; this needs `php artisan storage:link`. SVG, documents and the kept originals stay on a private disk and are always served through the app (see "Serving images" below).

- **Image extension:** PHP needs GD or Imagick, with WebP support, to optimize uploaded images. AVIF and HEIC need an Imagick build that can decode them. HEIC is never stored as HEIC: Safari converts it to JPEG in the browser, otherwise the server converts it to WebP (JPEG when the format setting keeps the original). Without such an Imagick, AVIF is stored as it is, and a HEIC the browser could not convert is refused with a hint to export it as JPG.
- **Upload limits:** the default limits are 30 MB per image and 20 MB per document (`config/media.php`). Set `upload_max_filesize` and `post_max_size` in `php.ini` to at least `32M`, or uploads fail before the app sees them. The Media tab shows the cap the server actually allows.
- **Optional S3-compatible storage:** by default files stay on the server. Under Admin → Site settings → Media you can send new uploads to S3, Cloudflare R2, Backblaze B2, DigitalOcean Spaces or MinIO (endpoint, region, bucket, keys, path-style for MinIO, optional public/CDN URL; "Test connection" checks them; `AWS_*` in `.env` work as defaults). The adapter `league/flysystem-aws-s3-v3` is already installed. Existing files move between the two with Admin → Media → move page or `php artisan media:move-disk s3|public`. The app keeps serving SVG itself and signs document links, so the bucket can stay private unless you set a public URL. For a long queue move, set `DB_QUEUE_RETRY_AFTER` above 1845 (see `docs/specs/media.md`, Known limitations). `php artisan media:prune-deleted` runs daily from the scheduler and removes replaced cloud objects after 7 days; without a cron the batches and the library page do it.
- **Serving images:** see the section below.
- **Memory:** very large photos that don't fit in `memory_limit` are stored without optimization, with a note in the file details.

### Serving images

Without the storage link nothing breaks: the same images are served through the app, which is just slower. The admin shows a warning in the media library to users who may edit site settings, and Admin → Site settings → Media has a "Create the storage link" button (and "Check again", which asks the browser to fetch a test file and so also catches a web server that does not follow symlinks or a wrong `APP_URL`). Until the first administrator opens an admin page after installation, images also go through the app.

Static files get their cache headers from the web server. Apache gets them from `public/.htaccess` (one week, only for images under `storage/media/`), so there is nothing to do. On nginx, add this to the site's `server` block, before the generic `location /`:

```nginx
location ^~ /storage/media/ {
    add_header Cache-Control "public, max-age=604800";
    try_files $uri /index.php?$query_string;
}
```

The `try_files` fallback sends a request for a missing file to Laravel, so an address that was handed out before the link broke still answers. Without these rules browsers still cache the files, because the file names change with every edit.

## Development

```bash
composer run dev        # the local development processes (php artisan dev)
php artisan test        # tests on SQLite in memory
composer lint           # format with Pint
composer types:check    # PHPStan
```

To run the tests against MySQL, create an empty database and override the connection:

```bash
DB_CONNECTION=mysql DB_DATABASE=app_testing DB_USERNAME=root DB_PASSWORD= php artisan test
```

GitHub Actions runs Pint and PHPStan, then runs the test suite on PHP 8.4 and 8.5 against both SQLite and MySQL 8.

Architecture notes and the decisions made during development are in [CLAUDE.md](CLAUDE.md).

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
php artisan db:seed      # creates the permissions and the system roles
php artisan admin:create # creates the first super admin
npm install && npm run build
```

`db:seed` is safe to run on every deploy. It only creates missing permissions, roles and the initial admin, and it never removes permissions that an administrator assigned.

### Shared hosting without SSH

1. Build the frontend locally (`npm run build`) and upload `public/build` together with the code.
2. Set `ADMIN_NAME`, `ADMIN_EMAIL` and `ADMIN_PASSWORD` in `.env`.
3. Run the migrations and the seeder from your hosting control panel (`php artisan migrate --force && php artisan db:seed --force`). The seeder creates that super admin if it does not exist yet.
4. Remove `ADMIN_PASSWORD` from `.env` after the first deploy.

Stage 1 does not queue any jobs. On hosting without workers, set `QUEUE_CONNECTION=sync`.

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

The site settings page also holds the site name, default language, timezone, email (SMTP) settings the light/dark logo (PNG, JPG, WebP or SVG; stored privately and served by the app, no `storage:link` needed) and the color scheme of the admin panel (nine presets or your company color). Values saved there override `.env`. After updating an existing install, run `php artisan migrate`, `php artisan permissions:sync` and `php artisan db:seed --class=RoleSeeder` so the `admin` role gets the new `settings.edit` permission.

## Media library

Admin → Media stores images and documents on a private disk and serves them through the app, so `php artisan storage:link` is not needed.

- **Image extension:** PHP needs GD or Imagick, with WebP support, to optimize uploaded images. AVIF and HEIC need an Imagick build that can decode them. HEIC is never stored as HEIC: Safari converts it to JPEG in the browser, otherwise the server converts it to WebP (JPEG when the format setting keeps the original). Without such an Imagick, AVIF is stored as it is, and a HEIC the browser could not convert is refused with a hint to export it as JPG.
- **Upload limits:** the default limits are 30 MB per image and 20 MB per document (`config/media.php`). Set `upload_max_filesize` and `post_max_size` in `php.ini` to at least `32M`, or uploads fail before the app sees them. The Media tab shows the cap the server actually allows.
- **Memory:** very large photos that don't fit in `memory_limit` are stored without optimization, with a note in the file details.

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

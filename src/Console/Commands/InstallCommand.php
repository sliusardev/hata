<?php

namespace Hata\Console\Commands;

use Hata\Concerns\ProfileValidationRules;
use Hata\Database\Seeders\HataSeeder;
use Hata\Enums\SystemRole;
use Hata\Hata;
use Hata\Models\Role;
use Hata\Support\Install\HostFileResult;
use Hata\Support\Install\HostFiles;
use Hata\Support\Install\HostFileStatus;
use Hata\Support\Install\HostPaths;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use LogicException;
use Symfony\Component\Console\Output\BufferedOutput;

use function Laravel\Prompts\password;
use function Laravel\Prompts\text;

/**
 * Installs Hata into a site: edits the site's own files where Laravel's skeleton has them, publishes the config and
 * the assets, migrates, seeds the permissions and roles and creates the first super admin. Every step is idempotent,
 * so the command may run again at any time; it ends with a report of each step: `done`, `already done` or
 * `do by hand` with the lines to add.
 */
#[Signature('hata:install
    {--name= : The name of the first super admin}
    {--email= : The email address of the first super admin}
    {--password= : The password of the first super admin (omit to be prompted securely)}
    {--no-migrate : Do not run the migrations}')]
#[Description('Install Hata into this site')]
class InstallCommand extends Command
{
    use ProfileValidationRules;

    private const string DONE = 'done';

    private const string ALREADY_DONE = 'already done';

    private const string BY_HAND = 'do by hand';

    private const string SKIPPED = 'skipped';

    private const string FAILED = 'failed';

    /**
     * @var list<array{label: string, status: string, lines: string}>
     */
    private array $steps = [];

    /**
     * Whether the user model's file has Hata's interface and trait (edited by this run or before).
     */
    private bool $userModelReady = false;

    public function handle(HostFiles $files): int
    {
        if ($this->laravel->configurationIsCached()) {
            $this->components->error('The config is cached, so a published config/hata.php and the ADMIN_* settings would not be read. Run `php artisan config:clear` first.');

            return self::FAILURE;
        }

        $paths = $this->laravel->bound(HostPaths::class)
            ? $this->laravel->make(HostPaths::class)
            : HostPaths::forApplication();

        $this->editHostFiles($files, $paths);
        $this->publishConfig($paths);
        $this->publishAssets();
        $migrated = $this->migrate();

        if ($migrated) {
            $this->seed();
        }

        $this->linkStorage();
        $this->report();

        return $migrated ? self::SUCCESS : self::FAILURE;
    }

    private function editHostFiles(HostFiles $files, HostPaths $paths): void
    {
        $user = $files->addUserTrait($paths->userModel);
        $this->userModelReady = $user->status !== HostFileStatus::Unrecognized;
        $this->fileStep("User model ({$paths->relative($paths->userModel)})", $user);

        $welcome = $files->removeWelcomeRoute($paths->webRoutes);
        $this->fileStep("Welcome route ({$paths->relative($paths->webRoutes)})", $welcome);

        // the view goes with the route this run removed; once the route is gone, the view may be the site's own
        if ($welcome->status !== HostFileStatus::Unrecognized) {
            $deleted = $welcome->status === HostFileStatus::Changed
                && File::exists($paths->welcomeView)
                && File::delete($paths->welcomeView);
            $this->step("Welcome view ({$paths->relative($paths->welcomeView)})", $deleted ? self::DONE : self::ALREADY_DONE);
        }

        $this->fileStep("Host defaults ({$paths->relative($paths->appServiceProvider)})", $files->addHostDefaults($paths->appServiceProvider));
        $this->fileStep("Seeder call ({$paths->relative($paths->databaseSeeder)})", $files->addSeederCall($paths->databaseSeeder));
        $this->fileStep("Asset publish on composer update ({$paths->relative($paths->composerJson)})", $files->ensureAssetsPublishScript($paths->composerJson));
    }

    /**
     * The same copy as `vendor:publish --tag=hata-config`, never over a site's own file.
     */
    private function publishConfig(HostPaths $paths): void
    {
        $label = "Config ({$paths->relative($paths->config)})";

        if (File::exists($paths->config)) {
            $this->step($label, self::ALREADY_DONE);

            return;
        }

        File::ensureDirectoryExists(dirname($paths->config));
        File::copy(Hata::path('config/hata.php'), $paths->config);

        $this->step($label, self::DONE);
    }

    /**
     * Every package's `laravel-assets`, as the skeleton's `post-update-cmd` publishes them (Hata's land in
     * `public/vendor/hata`).
     */
    private function publishAssets(): void
    {
        [$exitCode, $output] = $this->runArtisan('vendor:publish', ['--tag' => 'laravel-assets', '--force' => true]);

        $exitCode === self::SUCCESS
            ? $this->step('Assets (public/vendor/hata)', self::DONE)
            : $this->step('Assets (public/vendor/hata)', self::FAILED, $output);
    }

    private function migrate(): bool
    {
        if ($this->option('no-migrate')) {
            $this->step('Migrations', self::SKIPPED);

            return true;
        }

        [$exitCode, $output] = $this->runArtisan('migrate', ['--force' => true]);

        if ($exitCode !== self::SUCCESS) {
            $this->step('Migrations', self::FAILED, $output);

            return false;
        }

        $this->step('Migrations', str_contains($output, 'Nothing to migrate') ? self::ALREADY_DONE : self::DONE);

        return true;
    }

    /**
     * Hata's seeding (`HataSeeder`: permissions, roles and the super admin of `hata.admin.initial_admin`), with
     * the super admin taken from the `ADMIN_*` settings first, then the options, then the prompts. A password is
     * never made up: without one, the admin is left to `admin:create`.
     *
     * Hata's provider checks the user model while the application boots, so on a first install this process holds
     * the host's `User` as it was before the edit: the seeder then runs in a new process, which loads the edited one.
     */
    private function seed(): void
    {
        if (! Schema::hasTable('permissions') || ! Schema::hasTable('users')) {
            $this->step('Permissions and roles', self::BY_HAND, "php artisan migrate\nphp artisan hata:install");

            return;
        }

        try {
            Hata::userModel();
            $loaded = true;
        } catch (LogicException $exception) {
            if (! $this->userModelReady) {
                $this->step('Permissions and roles', self::BY_HAND, $exception->getMessage()."\nFix the user model, then run: php artisan hata:install");

                return;
            }

            $loaded = false;
        }

        $admin = $this->admin();
        $existed = $admin !== null && $this->userExists($admin['email']);

        [$seeded, $output] = $loaded ? $this->seedHere($admin) : $this->seedInNewProcess($admin);

        if (! $seeded) {
            $this->step('Permissions and roles', self::FAILED, $output."\nphp artisan db:seed --class='".HataSeeder::class."' --force");

            return;
        }

        $this->step('Permissions and roles', self::DONE);

        if ($admin !== null && $this->userExists($admin['email'])) {
            $this->step("Super admin ({$admin['email']})", $existed ? self::ALREADY_DONE : self::DONE);
        }
    }

    /**
     * @param  array{name: string, email: string, password: string}|null  $admin
     * @return array{bool, string}
     */
    private function seedHere(?array $admin): array
    {
        $configured = config('hata.admin.initial_admin');

        config(['hata.admin.initial_admin' => $admin ?? ['name' => null, 'email' => null, 'password' => null]]);

        try {
            $this->laravel->make(HataSeeder::class)->setContainer($this->laravel)->__invoke();
        } finally {
            config(['hata.admin.initial_admin' => $configured]);
        }

        return [true, ''];
    }

    /**
     * The admin reaches the new process as the `ADMIN_*` variables, which win over the site's `.env` (the config is
     * never cached here: `handle()` refuses to run then).
     *
     * @param  array{name: string, email: string, password: string}|null  $admin
     * @return array{bool, string}
     */
    private function seedInNewProcess(?array $admin): array
    {
        $result = Process::path($this->laravel->basePath())
            ->env($admin === null ? [] : ['ADMIN_NAME' => $admin['name'], 'ADMIN_EMAIL' => $admin['email'], 'ADMIN_PASSWORD' => $admin['password']])
            ->timeout(600)
            ->run([PHP_BINARY, 'artisan', 'db:seed', '--class='.HataSeeder::class, '--force', '--no-interaction']);

        return [$result->successful(), trim($result->output()."\n".$result->errorOutput())];
    }

    /**
     * The super admin to create, or null when there is none to create (one exists, or the data is missing or
     * invalid: those steps are reported here).
     *
     * @return array{name: string, email: string, password: string}|null
     */
    private function admin(): ?array
    {
        /** @var array{name: string|null, email: string|null, password: string|null} $configured */
        $configured = config('hata.admin.initial_admin');

        if (filled($configured['email']) && filled($configured['password'])) {
            return [
                'name' => filled($configured['name']) ? (string) $configured['name'] : 'Administrator',
                'email' => (string) $configured['email'],
                'password' => (string) $configured['password'],
            ];
        }

        $given = array_filter([
            'name' => $this->option('name'),
            'email' => $this->option('email'),
            'password' => $this->option('password'),
        ], filled(...));

        if ($given === [] && $this->superAdminExists()) {
            $this->step('Super admin', self::ALREADY_DONE);

            return null;
        }

        if (count($given) < 3 && ! $this->input->isInteractive()) {
            $this->step('Super admin', self::BY_HAND, 'php artisan admin:create');

            return null;
        }

        $data = [
            'name' => $given['name'] ?? text('Name of the super admin', required: true),
            'email' => Str::lower((string) ($given['email'] ?? text('Email of the super admin', required: true))),
            'password' => $given['password'] ?? password('Password of the super admin', required: true),
        ];

        // an existing user is left as it is, as HataSeeder does
        if ($this->userExists($data['email'])) {
            return $data;
        }

        $validator = Validator::make($data, [
            'name' => $this->nameRules(),
            // not `emailRules()`: its unique rule loads the user model, which may be the host's one before the edit,
            // and an existing user was already left alone above
            'email' => ['required', 'string', 'email', 'max:255'],
            'password' => ['required', 'string', Password::default()],
        ]);

        if ($validator->fails()) {
            $this->step('Super admin', self::BY_HAND, implode("\n", [...$validator->errors()->all(), 'php artisan admin:create']));

            return null;
        }

        /** @var array{name: string, email: string, password: string} */
        return $validator->validated();
    }

    /**
     * By the configured class, which may still be the host's model before the edit (see `seed()`).
     */
    private function userExists(string $email): bool
    {
        $model = config('auth.providers.users.model');

        return is_string($model) && is_subclass_of($model, Model::class)
            && $model::query()->where('email', Str::lower($email))->exists();
    }

    private function superAdminExists(): bool
    {
        return Role::query()
            ->where(['name' => SystemRole::SuperAdmin->value, 'guard_name' => 'web'])
            ->first()
            ?->users()
            ->exists() ?? false;
    }

    /**
     * The links of `filesystems.links` (the `public` disk, where Hata's raster images are served from).
     */
    private function linkStorage(): void
    {
        /** @var array<string, string> $links */
        $links = config('filesystems.links', []);

        if (array_all(array_keys($links), fn (string $link): bool => file_exists($link) || is_link($link))) {
            $this->step('Storage link', self::ALREADY_DONE);

            return;
        }

        [$exitCode, $output] = $this->runArtisan('storage:link');

        $exitCode === self::SUCCESS
            ? $this->step('Storage link', self::DONE)
            : $this->step('Storage link', self::BY_HAND, "php artisan storage:link\n(or Admin → Site settings → Media → Create the storage link)\n".$output);
    }

    /**
     * @param  array<string, mixed>  $parameters
     * @return array{int, string}
     */
    private function runArtisan(string $command, array $parameters = []): array
    {
        $output = new BufferedOutput;
        $exitCode = Artisan::call($command, $parameters, $output);

        return [$exitCode, trim($output->fetch())];
    }

    private function fileStep(string $label, HostFileResult $result): void
    {
        $this->step($label, match ($result->status) {
            HostFileStatus::Changed => self::DONE,
            HostFileStatus::AlreadyDone => self::ALREADY_DONE,
            HostFileStatus::Unrecognized => self::BY_HAND,
        }, $result->lines);
    }

    private function step(string $label, string $status, string $lines = ''): void
    {
        $this->steps[] = ['label' => $label, 'status' => $status, 'lines' => $lines];
    }

    private function report(): void
    {
        $this->newLine();
        $this->components->info('Hata install report');

        foreach ($this->steps as $step) {
            $this->components->twoColumnDetail($step['label'], match ($step['status']) {
                self::DONE => '<fg=green>done</>',
                self::BY_HAND => '<fg=yellow>do by hand</>',
                self::FAILED => '<fg=red>failed</>',
                default => '<fg=gray>'.$step['status'].'</>',
            });
        }

        foreach ($this->steps as $step) {
            if (! in_array($step['status'], [self::BY_HAND, self::FAILED], true)) {
                continue;
            }

            $this->newLine();
            $this->line("<fg=yellow>{$step['label']}: {$step['status']}:</>");

            foreach (explode("\n", $step['lines']) as $line) {
                $this->line('    '.$line);
            }
        }

        $this->newLine();
        $this->components->bulletList([
            'Web server caching of images under /storage/media/: on Apache, use'
                .' vendor/sliusardev/hata/stubs/htaccess as public/.htaccess (Laravel\'s file plus the media rules);'
                .' on nginx, add the location block of the README, "Serving images".',
            'Keep `@php artisan vendor:publish --tag=laravel-assets --ansi --force` and `@php artisan view:clear --ansi`'
                .' in composer.json → post-update-cmd: every update of Hata needs both.',
        ]);

        if (Route::has('admin.dashboard')) {
            $this->components->info('Sign in to the admin at '.route('admin.dashboard'));
        }
    }
}

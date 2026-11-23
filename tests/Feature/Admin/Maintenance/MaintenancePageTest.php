<?php

use App\Models\User;
use Hata\Actions\Maintenance\RunMaintenanceAction;
use Hata\Actions\Media\MoveMediaToDisk;
use Hata\Enums\MaintenanceAction;
use Hata\Enums\MaintenanceStatus;
use Hata\Models\MaintenanceRun;
use Hata\Models\Role;
use Hata\Support\Maintenance\ArtisanRunner;
use Hata\Support\Maintenance\PendingMigrations;
use Hata\Support\Maintenance\SeederCatalog;
use Hata\Support\Settings\SiteSettings;
use Hata\Support\Toast;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Console\Events\CommandFinished;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Container\Container;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;
use Livewire\Livewire;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;

beforeEach(function () {
    seedRolesAndPermissions();
});

/**
 * Replace Artisan with a recorder, so no test rebuilds real caches. $result is what every command "returns";
 * a Throwable is thrown instead.
 *
 * @param  array{exit_code: int, output: string}|Throwable  $result
 */
function fakeArtisan(array|Throwable $result = ['exit_code' => 0, 'output' => 'Done.']): object
{
    $fake = new class($result) extends ArtisanRunner
    {
        /** @var list<array{0: string, 1: array<string, mixed>}> */
        public array $calls = [];

        /** @var list<bool> */
        public array $suspended = [];

        public function __construct(private array|Throwable $result) {}

        public function run(string $command, array $parameters = []): array
        {
            $this->calls[] = [$command, $parameters];
            $this->suspended[] = SiteSettings::buildingConfigCache();

            if ($this->result instanceof Throwable) {
                throw $this->result;
            }

            return $this->result;
        }
    };

    app()->instance(ArtisanRunner::class, $fake);

    return $fake;
}

/**
 * Post an action the way the confirmation dialog does.
 */
function runMaintenance(string $action, ?string $seeder = null, ?User $as = null): TestResponse
{
    return test()->actingAs($as ?? maintainer())->post(route('admin.maintenance.run'), ['action' => $action, 'seeder' => $seeder]);
}

function maintainer(): User
{
    return User::factory()->withRole('super-admin')->create(['name' => 'Root Admin']);
}

/**
 * Register a folder with one migration that has not run, and return its name.
 */
function pendingMigration(): string
{
    $directory = storage_path('framework/testing/maintenance-'.uniqid());
    File::ensureDirectoryExists($directory);
    File::put($directory.'/2099_01_01_000000_maintenance_test_noop.php', <<<'PHP'
        <?php

        use Illuminate\Database\Migrations\Migration;

        return new class extends Migration
        {
            public function up(): void {}
        };
        PHP);
    app('migrator')->path($directory);
    test()->beforeApplicationDestroyed(fn () => File::deleteDirectory($directory));

    return '2099_01_01_000000_maintenance_test_noop';
}

describe('access', function () {
    it('is forbidden without maintenance.run, even with the settings permission', function () {
        $fake = fakeArtisan();
        $user = User::factory()->withPermissions(['admin.access', 'settings.edit'])->create();
        $this->actingAs($user);

        $this->get(route('admin.maintenance'))->assertForbidden();
        Livewire::test('hata::admin.maintenance.index')->assertForbidden();
        runMaintenance('clear_caches', as: $user)->assertForbidden();

        expect($fake->calls)->toBe([])->and(MaintenanceRun::count())->toBe(0);
    });

    it('does not give the permission to the admin role, whose "*" skips reserved permissions', function () {
        $admin = User::factory()->withRole('admin')->create();

        expect(Role::findByName('admin')->hasPermissionTo('maintenance.run'))->toBeFalse()
            ->and(Role::findByName('admin')->hasPermissionTo('settings.edit'))->toBeTrue()
            ->and($admin->can('maintenance.run'))->toBeFalse();

        $this->actingAs($admin)->get(route('admin.settings.edit'))->assertOk()->assertDontSee(route('admin.maintenance'));
    });

    it('opens for a super admin and for a role that was given the permission, with the sidebar item', function () {
        $this->actingAs(maintainer())->get(route('admin.maintenance'))
            ->assertOk()
            ->assertSee(__('hata::maintenance.title'))
            ->assertSee(route('admin.maintenance'));

        $this->actingAs(User::factory()->withPermissions(['admin.access', 'maintenance.run'])->create())
            ->get(route('admin.maintenance'))
            ->assertOk();
    });

    it('refuses the action itself without the permission', function () {
        fakeArtisan();
        $user = User::factory()->withPermissions(['admin.access', 'settings.edit'])->create();

        expect(fn () => app(RunMaintenanceAction::class)->handle($user, MaintenanceAction::ClearCaches))
            ->toThrow(AuthorizationException::class);
        expect(MaintenanceRun::count())->toBe(0);
    });

    it('sends guests to the login page', function () {
        $this->get(route('admin.maintenance'))->assertRedirect(route('login'));
        $this->post(route('admin.maintenance.run'), ['action' => 'migrate'])->assertRedirect(route('login'));
    });
});

describe('migrations', function () {
    it('says everything is applied and offers nothing to run', function () {
        Livewire::actingAs(maintainer())->test('hata::admin.maintenance.index')
            ->assertSeeHtml('data-test="maintenance-nothing-pending"')
            ->assertDontSeeHtml('data-test="maintenance-run-migrate"')
            ->call('confirm', 'migrate')
            ->assertSet('showConfirmModal', false);
    });

    it('lists the pending migrations, confirms with a form that posts the action, and shows the result', function () {
        $name = pendingMigration();
        $fake = fakeArtisan(['exit_code' => 0, 'output' => "INFO  Running migrations.\n{$name} ... DONE"]);

        Livewire::actingAs(maintainer())->test('hata::admin.maintenance.index')
            ->assertSee($name)
            ->assertSeeHtml('data-test="maintenance-pending-count"')
            ->call('confirm', 'migrate')
            ->assertSet('showConfirmModal', true)
            ->assertSee(trans_choice('hata::maintenance.actions.migrate.confirm', 1, ['count' => 1]))
            ->assertSeeHtml('action="'.route('admin.maintenance.run').'"')
            ->assertSeeHtml('name="action" value="migrate"');

        runMaintenance('migrate')
            ->assertRedirect(route('admin.maintenance'))
            ->assertSessionHas(Toast::SESSION_KEY, fn (array $toast): bool => $toast['variant'] === 'success');

        $run = MaintenanceRun::query()->sole();

        expect($fake->calls)->toBe([['migrate', ['--force' => true]]])
            ->and($run->action)->toBe(MaintenanceAction::Migrate)
            ->and($run->status)->toBe(MaintenanceStatus::Succeeded)
            ->and($run->user_name)->toBe('Root Admin')
            ->and($run->slot)->toBeNull()
            ->and($run->finished_at)->not->toBeNull()
            ->and(session('maintenance.last_run'))->toBe($run->id);

        // The page the redirect lands on shows the run.
        Livewire::actingAs(maintainer())->test('hata::admin.maintenance.index')
            ->assertSet('lastRunId', $run->id)
            ->assertSeeHtml('data-test="maintenance-last-result"')
            ->assertSee('Running migrations.')
            ->assertSee(MaintenanceStatus::Succeeded->label());
    });

    it('reads pending migrations through the migrator and really runs them', function () {
        $name = pendingMigration();

        expect(app(PendingMigrations::class)->names())->toBe([$name]);

        $result = app(ArtisanRunner::class)->run('migrate', ['--force' => true]);

        expect($result['exit_code'])->toBe(0)
            ->and($result['output'])->toContain($name)
            ->and($result['output'])->not->toContain("\e[")
            ->and(app(PendingMigrations::class)->names())->toBe([]);
    });

    it('can run the migrations before its own journal table exists, and nothing else', function () {
        $fake = fakeArtisan(['exit_code' => 0, 'output' => 'Migrated.']);
        $actor = maintainer();
        Schema::drop('maintenance_runs');

        Livewire::actingAs($actor)->test('hata::admin.maintenance.index')->assertOk()->set('tab', 'journal')->assertOk();

        runMaintenance('clear_caches', as: $actor)
            ->assertRedirect(route('admin.maintenance'))
            ->assertSessionHas(Toast::SESSION_KEY, fn (array $toast): bool => $toast['text'] === __('hata::maintenance.errors.migrate_first'));

        $run = app(RunMaintenanceAction::class)->handle($actor, MaintenanceAction::Migrate);

        expect($fake->calls)->toBe([['migrate', ['--force' => true]]])
            ->and($run->status)->toBe(MaintenanceStatus::Succeeded)
            ->and($run->exists)->toBeFalse();
    })->skip(fn () => DB::getDriverName() !== 'sqlite', 'Dropping a table ends the test transaction on this driver.');
});

describe('seeders', function () {
    it('finds the host\'s seeders, DatabaseSeeder first', function () {
        $catalog = app(SeederCatalog::class);

        expect($catalog->names())->toBe(['DatabaseSeeder', 'DemoSeeder'])
            ->and($catalog->resolve('DemoSeeder'))->toBe('Database\Seeders\DemoSeeder')
            ->and($catalog->resolve('Database\Seeders\DemoSeeder'))->toBeNull()
            ->and($catalog->resolve('../../app/Models/User'))->toBeNull();
    });

    it('runs the chosen seeder by its full class name', function () {
        $fake = fakeArtisan();

        Livewire::actingAs(maintainer())->test('hata::admin.maintenance.index')
            ->assertSet('seeder', 'DatabaseSeeder')
            ->set('seeder', 'DemoSeeder')
            ->call('confirm', 'seed')
            ->assertSee(__('hata::maintenance.actions.seed.confirm', ['seeder' => 'DemoSeeder']))
            ->assertSeeHtml('name="seeder" value="DemoSeeder"')
            // The dialog keeps posting the seeder it named, whatever the select is changed to behind it.
            ->set('seeder', 'DatabaseSeeder')
            ->assertSeeHtml('name="seeder" value="DemoSeeder"');

        runMaintenance('seed', 'DemoSeeder')->assertRedirect(route('admin.maintenance'));

        expect($fake->calls)->toBe([['db:seed', ['--class' => 'Database\Seeders\DemoSeeder', '--force' => true]]])
            ->and(MaintenanceRun::query()->sole()->title())->toBe(__('hata::maintenance.actions.seed.title').': DemoSeeder');
    });

    it('never runs a class that is not a listed seeder', function (string $tampered) {
        $fake = fakeArtisan();
        $actor = maintainer();

        Livewire::actingAs($actor)->test('hata::admin.maintenance.index')
            ->set('seeder', $tampered)
            ->call('confirm', 'seed')
            ->assertSet('showConfirmModal', false);

        runMaintenance('seed', $tampered, $actor)
            ->assertRedirect(route('admin.maintenance'))
            ->assertSessionHas(Toast::SESSION_KEY, fn (array $toast): bool => $toast['text'] === __('hata::maintenance.errors.unknown_seeder'));

        expect($fake->calls)->toBe([])->and(MaintenanceRun::count())->toBe(0);
    })->with(['App\Models\User', 'Database\Seeders\DemoSeeder', '../DemoSeeder', '', 'DemoSeeder --database=other']);
});

describe('caches', function () {
    it('clears the caches', function () {
        $fake = fakeArtisan();

        runMaintenance('clear_caches')->assertRedirect(route('admin.maintenance'));

        expect($fake->calls)->toBe([['optimize:clear', []]])->and($fake->suspended)->toBe([false]);
    });

    it('refuses to clear the caches while a media batch holds its cache lock', function () {
        $fake = fakeArtisan();
        $lock = Cache::lock(MoveMediaToDisk::LOCK, 60);
        $lock->get();

        runMaintenance('clear_caches')
            ->assertRedirect(route('admin.maintenance'))
            ->assertSessionHas(Toast::SESSION_KEY, fn (array $toast): bool => $toast['text'] === __('hata::maintenance.errors.media_batch_running'))
            ->assertSessionMissing('maintenance.last_run');

        $lock->release();

        expect($fake->calls)->toBe([])->and(MaintenanceRun::count())->toBe(0);
    });

    it('rebuilds the caches with the stored site settings switched off', function () {
        $fake = fakeArtisan();

        runMaintenance('rebuild_caches')->assertRedirect(route('admin.maintenance'));

        expect($fake->calls)->toBe([['optimize', []]])
            ->and($fake->suspended)->toBe([true])
            ->and(SiteSettings::buildingConfigCache())->toBeFalse();
    });

    it('keeps stored settings out of the config while the config cache is built', function () {
        app(SiteSettings::class)->put(['site_name' => 'Stored name']);
        config(['app.name' => 'From env']);

        SiteSettings::withoutApplying(fn () => app(SiteSettings::class)->apply());
        expect(config('app.name'))->toBe('From env');

        app(SiteSettings::class)->apply();
        expect(config('app.name'))->toBe('Stored name');
    });

    it('switches the stored settings off while a config-caching command runs, however it was started', function (string $command, bool $expected) {
        $input = new ArrayInput([]);
        $output = new NullOutput;

        event(new CommandStarting($command, $input, $output));
        expect(SiteSettings::buildingConfigCache())->toBe($expected);

        event(new CommandFinished($command, $input, $output, 0));
        expect(SiteSettings::buildingConfigCache())->toBeFalse();
    })->with([['config:cache', true], ['optimize', true], ['optimize:clear', false], ['migrate', false]]);

    it('really rebuilds the caches in a request and leaves the application and the response intact', function () {
        // Every cache file goes to a scratch folder, so neither the project nor the rest of the suite sees them.
        $directory = storage_path('framework/testing/optimize-'.uniqid());
        File::ensureDirectoryExists($directory.'/views');
        $paths = [
            'APP_CONFIG_CACHE' => $directory.'/config.php',
            'APP_ROUTES_CACHE' => $directory.'/routes.php',
            'APP_EVENTS_CACHE' => $directory.'/events.php',
            'VIEW_COMPILED_PATH' => $directory.'/views',
        ];

        foreach ($paths as $key => $path) {
            $_ENV[$key] = $_SERVER[$key] = $path;
        }

        config(['view.compiled' => $directory.'/views']);

        try {
            expect(app()->getCachedConfigPath())->toBe($paths['APP_CONFIG_CACHE'])
                ->and(app()->getCachedRoutesPath())->toBe($paths['APP_ROUTES_CACHE'])
                ->and(app()->getCachedEventsPath())->toBe($paths['APP_EVENTS_CACHE']);

            app(SiteSettings::class)->put(['site_name' => 'Stored name']);
            $application = app();
            $resolver = Model::getConnectionResolver();

            runMaintenance('rebuild_caches')
                ->assertRedirect(route('admin.maintenance'))
                ->assertSessionHas(Toast::SESSION_KEY, fn (array $toast): bool => $toast['variant'] === 'success');

            $run = MaintenanceRun::query()->sole();

            expect($run->status)->toBe(MaintenanceStatus::Succeeded, $run->output ?? '')
                ->and($run->output)->toContain('config', 'routes', 'views')
                ->and(session('maintenance.last_run'))->toBe($run->id)
                ->and(Container::getInstance())->toBe($application)
                ->and(Facade::getFacadeApplication())->toBe($application)
                ->and(Model::getConnectionResolver())->toBe($resolver)
                ->and(SiteSettings::buildingConfigCache())->toBeFalse()
                ->and(config('app.name'))->toBe('Stored name');

            // The stored setting is applied on every request, never frozen into the cache file.
            expect((require $paths['APP_CONFIG_CACHE'])['app']['name'])->not->toBe('Stored name');
        } finally {
            foreach (array_keys($paths) as $key) {
                unset($_ENV[$key], $_SERVER[$key]);
            }

            File::deleteDirectory($directory);
        }
    });

    it('warns more strongly about rebuilding in a local environment', function () {
        $page = Livewire::actingAs(maintainer())->test('hata::admin.maintenance.index');

        $page->call('confirm', 'rebuild_caches')->assertDontSeeHtml('data-test="maintenance-local-warning"');

        app()->detectEnvironment(fn () => 'local');
        $page->call('confirm', 'rebuild_caches')->assertSeeHtml('data-test="maintenance-local-warning"');
    });
});

describe('running and the journal', function () {
    it('refuses an action that is not on the list', function (string $action) {
        $fake = fakeArtisan();

        Livewire::actingAs(maintainer())->test('hata::admin.maintenance.index')
            ->call('confirm', $action)
            ->assertSet('showConfirmModal', false);

        runMaintenance($action)->assertSessionHasErrors('action');

        expect($fake->calls)->toBe([])->and(MaintenanceRun::count())->toBe(0);
    })->with(['migrate:fresh', 'optimize', 'db:wipe', '']);

    it('records a non-zero exit code as a failed run', function () {
        fakeArtisan(['exit_code' => 1, 'output' => 'SQLSTATE[42S01]: table already exists']);

        runMaintenance('clear_caches')
            ->assertRedirect(route('admin.maintenance'))
            ->assertSessionHas(Toast::SESSION_KEY, fn (array $toast): bool => $toast['variant'] === 'danger');

        expect(MaintenanceRun::query()->sole())
            ->status->toBe(MaintenanceStatus::Failed)
            ->exit_code->toBe(1)
            ->slot->toBeNull();

        Livewire::actingAs(maintainer())->test('hata::admin.maintenance.index')
            ->assertSee('SQLSTATE[42S01]')
            ->assertSee(MaintenanceStatus::Failed->label());
    });

    it('records a command that throws as a failed run with its message', function () {
        fakeArtisan(new RuntimeException('disk full'));

        $run = app(RunMaintenanceAction::class)->handle(maintainer(), MaintenanceAction::ClearCaches);

        expect($run->status)->toBe(MaintenanceStatus::Failed)
            ->and($run->output)->toBe('RuntimeException: disk full')
            ->and($run->slot)->toBeNull();
    });

    it('stores output that is not valid UTF-8 instead of failing the run', function () {
        fakeArtisan(['exit_code' => 0, 'output' => "Seeded \xC0\xAF rows"]);

        $run = app(RunMaintenanceAction::class)->handle(maintainer(), MaintenanceAction::ClearCaches);

        expect($run->status)->toBe(MaintenanceStatus::Succeeded)
            ->and(mb_check_encoding($run->output, 'UTF-8'))->toBeTrue()
            ->and($run->output)->toStartWith('Seeded ');
    });

    it('lets only one run happen at a time and frees a dead one after half an hour', function () {
        $fake = fakeArtisan();
        $actor = maintainer();
        $stuck = MaintenanceRun::factory()->running()->create();

        runMaintenance('clear_caches', as: $actor)
            ->assertSessionHas(Toast::SESSION_KEY, fn (array $toast): bool => $toast['text'] === __('hata::maintenance.errors.busy'));
        expect($fake->calls)->toBe([])->and(MaintenanceRun::count())->toBe(1);

        $this->travel(MaintenanceRun::STALE_MINUTES + 1)->minutes();
        expect($stuck->refresh()->shownStatus())->toBe(MaintenanceStatus::Interrupted);

        app(RunMaintenanceAction::class)->handle($actor, MaintenanceAction::ClearCaches);

        expect($fake->calls)->toHaveCount(1)
            ->and($stuck->refresh()->status)->toBe(MaintenanceStatus::Interrupted)
            ->and($stuck->slot)->toBeNull()
            ->and($stuck->finished_at)->not->toBeNull();
    });

    it('keeps only the end of a long output and says so', function () {
        fakeArtisan(['exit_code' => 0, 'output' => str_repeat('a', RunMaintenanceAction::OUTPUT_LIMIT).'THE END']);

        $run = app(RunMaintenanceAction::class)->handle(maintainer(), MaintenanceAction::ClearCaches);

        expect(strlen($run->output))->toBe(RunMaintenanceAction::OUTPUT_LIMIT)
            ->and($run->output)->toEndWith('THE END')
            ->and($run->output_truncated)->toBeTrue();
    });

    it('keeps the latest 200 runs', function () {
        fakeArtisan();
        MaintenanceRun::factory()->count(RunMaintenanceAction::KEEP + 4)->create();
        $oldest = MaintenanceRun::query()->min('id');

        $run = app(RunMaintenanceAction::class)->handle(maintainer(), MaintenanceAction::ClearCaches);

        expect(MaintenanceRun::count())->toBe(RunMaintenanceAction::KEEP)
            ->and(MaintenanceRun::query()->whereKey($oldest)->exists())->toBeFalse()
            ->and(MaintenanceRun::query()->whereKey($run->id)->exists())->toBeTrue();
    });

    it('shows the journal with who ran what and opens an entry\'s output', function () {
        $run = MaintenanceRun::factory()->create([
            'user_name' => 'Olena',
            'action' => MaintenanceAction::Seed,
            'parameter' => 'RoleSeeder',
            'status' => MaintenanceStatus::Failed,
            'output' => 'Seeder exploded',
        ]);

        Livewire::actingAs(maintainer())->test('hata::admin.maintenance.index')
            ->assertDontSee('Olena')
            ->set('tab', 'journal')
            ->assertSeeHtml('data-test="maintenance-journal-tab"')
            ->assertDontSeeHtml('data-test="maintenance-migrate"')
            ->assertSee('Olena')
            ->assertSee(__('hata::maintenance.actions.seed.title').': RoleSeeder')
            ->assertSee(MaintenanceStatus::Failed->label())
            ->assertDontSee('Seeder exploded')
            ->call('showOutput', $run->id)
            ->assertSet('showOutputModal', true)
            ->assertSee('Seeder exploded')
            ->call('showOutput', 999999)
            ->assertSet('showOutputModal', false);
    });

    it('keeps an entry readable after its user is deleted', function () {
        fakeArtisan();
        $actor = maintainer();
        $run = app(RunMaintenanceAction::class)->handle($actor, MaintenanceAction::ClearCaches);

        User::factory()->withRole('super-admin')->create();
        $actor->delete();

        expect($run->refresh()->user_id)->toBeNull()->and($run->user_name)->toBe('Root Admin');
    });

    it('loads only the open tab\'s data and falls back to the actions for an unknown tab', function () {
        MaintenanceRun::factory()->create();
        $queries = function (string $tab): Collection {
            DB::flushQueryLog();
            DB::enableQueryLog();
            Livewire::withQueryParams(['tab' => $tab])->actingAs(maintainer())->test('hata::admin.maintenance.index');
            DB::disableQueryLog();

            return collect(DB::getQueryLog())->pluck('query');
        };

        expect($queries('actions')->filter(fn (string $query): bool => str_contains($query, 'maintenance_runs')))->toBeEmpty()
            ->and($queries('journal')->filter(fn (string $query): bool => str_contains($query, '"migrations"') || str_contains($query, '`migrations`')))->toBeEmpty();

        Livewire::withQueryParams(['tab' => 'database'])->actingAs(maintainer())->test('hata::admin.maintenance.index')
            ->assertSet('tab', 'actions')
            ->assertSeeHtml('data-test="maintenance-migrate"');
    });

    it('does not load outputs for the journal list', function () {
        MaintenanceRun::factory()->count(3)->create(['output' => 'big output']);

        $runs = Livewire::actingAs(maintainer())->test('hata::admin.maintenance.index')->set('tab', 'journal')->instance()->runs;

        expect($runs->first()->getAttributes())->not->toHaveKey('output');
    });
});

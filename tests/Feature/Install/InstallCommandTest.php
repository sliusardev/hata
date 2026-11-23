<?php

use App\Models\User;
use Hata\Hata;
use Hata\Support\Assets\HataAssets;
use Hata\Support\Install\HostPaths;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->host = sys_get_temp_dir().'/hata-install-'.Str::random(8);

    File::copyDirectory(dirname(__DIR__, 2).'/fixtures/host', $this->host);
    File::ensureDirectoryExists($this->host.'/resources/views');
    File::put($this->host.'/resources/views/welcome.blade.php', '<h1>Laravel</h1>');
    File::ensureDirectoryExists($this->host.'/config');
    File::ensureDirectoryExists($this->host.'/storage/app/public');

    $this->app->instance(HostPaths::class, HostPaths::under($this->host));
    config(['filesystems.links' => [$this->host.'/public/storage' => $this->host.'/storage/app/public']]);
    File::ensureDirectoryExists($this->host.'/public');

    // `laravel-assets` is published into the test application's public directory
    $this->publishedAssets = public_path(HataAssets::DIRECTORY);
    $this->hadPublishedAssets = is_dir($this->publishedAssets);
});

afterEach(function () {
    File::deleteDirectory($this->host);

    if (! $this->hadPublishedAssets) {
        File::deleteDirectory($this->publishedAssets);
    }
});

/**
 * The contents of the host's five edited files, the published config and the welcome view.
 *
 * @return array<string, string|null>
 */
function hostSnapshot(string $host): array
{
    return collect([
        'app/Models/User.php',
        'routes/web.php',
        'app/Providers/AppServiceProvider.php',
        'database/seeders/DatabaseSeeder.php',
        'composer.json',
        'config/hata.php',
        'resources/views/welcome.blade.php',
    ])->mapWithKeys(fn (string $file) => [$file => File::exists("{$host}/{$file}") ? File::get("{$host}/{$file}") : null])->all();
}

it('installs on a fresh skeleton', function () {
    $skeleton = hostSnapshot($this->host);

    $this->artisan('hata:install', ['--name' => 'Owner', '--email' => 'owner@example.test', '--password' => 'secret-password'])
        ->expectsOutputToContain('Hata install report')
        ->doesntExpectOutputToContain('do by hand')
        ->assertSuccessful();

    $installed = hostSnapshot($this->host);

    foreach (['app/Models/User.php', 'routes/web.php', 'app/Providers/AppServiceProvider.php', 'database/seeders/DatabaseSeeder.php', 'composer.json'] as $file) {
        expect($installed[$file])->not->toBe($skeleton[$file], "{$file} was not edited");
    }

    expect($installed['app/Models/User.php'])->toContain('implements AdminUser')
        ->and($installed['routes/web.php'])->not->toContain('welcome')
        ->and($installed['resources/views/welcome.blade.php'])->toBeNull()
        ->and($installed['config/hata.php'])->toBe(File::get(Hata::path('config/hata.php')))
        ->and(is_dir($this->publishedAssets))->toBeTrue()
        ->and(is_link($this->host.'/public/storage'))->toBeTrue();

    $owner = User::query()->where('email', 'owner@example.test')->sole();

    expect($owner->name)->toBe('Owner')
        ->and($owner->hasRole('super-admin'))->toBeTrue();
});

it('is safe to run twice', function () {
    $options = ['--name' => 'Owner', '--email' => 'owner@example.test', '--password' => 'secret-password'];

    $this->artisan('hata:install', $options)->assertSuccessful();
    $installed = hostSnapshot($this->host);

    $this->artisan('hata:install', $options)
        ->doesntExpectOutputToContain('do by hand')
        ->assertSuccessful();

    expect(hostSnapshot($this->host))->toBe($installed)
        ->and(User::query()->count())->toBe(1);
});

it('reports what it could not do', function () {
    File::put($this->host.'/app/Models/User.php', str_replace(
        'class User extends Authenticatable',
        'class User extends BaseUser',
        File::get($this->host.'/app/Models/User.php'),
    ));
    $before = File::get($this->host.'/app/Models/User.php');

    $this->artisan('hata:install', ['--name' => 'Owner', '--email' => 'owner@example.test', '--password' => 'secret-password'])
        ->expectsOutputToContain('do by hand')
        ->expectsOutputToContain('use Hata\Models\Concerns\IsAdminUser;')
        ->assertSuccessful();

    expect(File::get($this->host.'/app/Models/User.php'))->toBe($before);
});

it('creates the super admin from the ADMIN_* settings first', function () {
    config(['hata.admin.initial_admin' => ['name' => 'From Env', 'email' => 'env@example.test', 'password' => 'secret-password']]);

    $this->artisan('hata:install', ['--name' => 'Owner', '--email' => 'owner@example.test', '--password' => 'secret-password'])
        ->assertSuccessful();

    expect(User::query()->pluck('email')->all())->toBe(['env@example.test']);
});

it('asks for the super admin on an interactive console and never invents a password', function () {
    $this->artisan('hata:install')
        ->expectsQuestion('Name of the super admin', 'Owner')
        ->expectsQuestion('Email of the super admin', 'owner@example.test')
        ->expectsQuestion('Password of the super admin', 'secret-password')
        ->assertSuccessful();

    expect(User::query()->where('email', 'owner@example.test')->exists())->toBeTrue();

    $this->artisan('hata:install', ['--no-interaction' => true])->assertSuccessful();

    User::query()->delete();

    $this->artisan('hata:install', ['--no-interaction' => true])
        ->expectsOutputToContain('php artisan admin:create')
        ->assertSuccessful();

    expect(User::query()->count())->toBe(0);
});

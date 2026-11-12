<?php

use App\Actions\Media\CreateStorageLink;
use App\Exceptions\StorageLinkException;
use App\Models\User;
use App\Support\Media\DirectServing;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;

uses(RefreshDatabase::class);

beforeEach(function () {
    seedRolesAndPermissions();
});

afterEach(function () {
    foreach (glob(sys_get_temp_dir().'/storage-link-*') ?: [] as $dir) {
        File::deleteDirectory($dir);
    }
});

/**
 * Point the `public` disk and its link at a temp directory, so the real public/storage is never touched.
 * With `$parentExists` false the link would have to be created inside a directory that does not exist.
 */
function storageLinkSandbox(bool $parentExists = true): string
{
    $base = sys_get_temp_dir().'/storage-link-'.bin2hex(random_bytes(4));
    File::ensureDirectoryExists($base.'/root');

    if ($parentExists) {
        File::ensureDirectoryExists($base.'/web');
    }

    config([
        'filesystems.disks.public.root' => $base.'/root',
        'filesystems.links' => [$base.'/web/storage' => $base.'/root'],
    ]);
    app()->forgetInstance(DirectServing::class);

    return $base;
}

it('creates the link for a user who can edit settings', function () {
    $base = storageLinkSandbox();
    $actor = User::factory()->withPermissions(['settings.edit'])->create();

    expect(app(DirectServing::class)->linked())->toBeFalse();

    app(CreateStorageLink::class)->handle($actor);

    expect(is_link($base.'/web/storage'))->toBeTrue()
        ->and(app(DirectServing::class)->linked())->toBeTrue();
});

it('succeeds when the link already exists', function () {
    storageLinkSandbox();
    $actor = User::factory()->withPermissions(['settings.edit'])->create();

    app(CreateStorageLink::class)->handle($actor);
    app(CreateStorageLink::class)->handle($actor);

    expect(app(DirectServing::class)->linked())->toBeTrue();
});

it('refuses a user who cannot edit settings and creates nothing', function () {
    $base = storageLinkSandbox();
    $actor = User::factory()->withPermissions(['admin.access'])->create();

    expect(fn () => app(CreateStorageLink::class)->handle($actor))->toThrow(AuthorizationException::class)
        ->and(is_link($base.'/web/storage'))->toBeFalse();
});

it('fails with the command to run by hand when the link cannot be created', function () {
    storageLinkSandbox(parentExists: false);
    $actor = User::factory()->withPermissions(['settings.edit'])->create();

    try {
        app(CreateStorageLink::class)->handle($actor);
        $this->fail('The link cannot be created, so an exception was expected.');
    } catch (StorageLinkException $exception) {
        expect($exception->getMessage())->toContain('php artisan storage:link')
            ->and($exception->getMessage())->not->toContain('media.storage.direct');
    }
});

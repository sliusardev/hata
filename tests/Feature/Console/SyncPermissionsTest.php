<?php

use App\Support\Permissions\PermissionRegistry;
use Spatie\Permission\Models\Permission;

test('it creates every registered permission', function () {
    $this->artisan('permissions:sync')->assertSuccessful();

    expect(Permission::pluck('name')->sort()->values()->all())->toBe([
        'admin.access',
        'roles.create',
        'roles.delete',
        'roles.edit',
        'roles.view',
        'users.create',
        'users.delete',
        'users.edit',
        'users.view',
    ]);
});

test('running it again does not duplicate permissions', function () {
    $this->artisan('permissions:sync');

    $this->artisan('permissions:sync')->assertSuccessful();

    expect(Permission::count())->toBe(9);
});

test('it creates permissions registered by modules', function () {
    app(PermissionRegistry::class)->register('pages', ['view', 'edit']);

    $this->artisan('permissions:sync')->assertSuccessful();

    $this->assertDatabaseHas('permissions', ['name' => 'pages.view', 'guard_name' => 'web']);
    $this->assertDatabaseHas('permissions', ['name' => 'pages.edit', 'guard_name' => 'web']);
});

test('it lists permissions that are no longer registered without deleting them', function () {
    Permission::create(['name' => 'legacy.view', 'guard_name' => 'web']);

    $this->artisan('permissions:sync')
        ->expectsOutputToContain('legacy.view')
        ->assertSuccessful();

    $this->assertDatabaseHas('permissions', ['name' => 'legacy.view']);
});

test('it deletes permissions that are no longer registered with --prune', function () {
    Permission::create(['name' => 'legacy.view', 'guard_name' => 'web']);

    $this->artisan('permissions:sync', ['--prune' => true])->assertSuccessful();

    $this->assertDatabaseMissing('permissions', ['name' => 'legacy.view']);
    $this->assertDatabaseHas('permissions', ['name' => 'users.view']);
});

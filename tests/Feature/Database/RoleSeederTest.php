<?php

use App\Models\User;
use Hata\Database\Seeders\DatabaseSeeder;
use Hata\Models\Role;

test('it creates the system roles with their default permissions', function () {
    seedRolesAndPermissions();

    $roles = Role::with('permissions')->get()->keyBy('name');

    expect($roles->keys()->sort()->values()->all())->toBe(['admin', 'author', 'editor', 'super-admin'])
        ->and($roles->every(fn (Role $role): bool => $role->is_system))->toBeTrue()
        ->and($roles['super-admin']->permissions)->toBeEmpty()
        ->and($roles['admin']->permissions->pluck('name')->sort()->values()->all())->toBe([
            'admin.access',
            'media.delete',
            'media.edit',
            'media.upload',
            'media.view',
            'pages.create',
            'pages.delete',
            'pages.edit',
            'pages.view',
            'roles.create',
            'roles.delete',
            'roles.edit',
            'roles.view',
            'settings.edit',
            'users.create',
            'users.delete',
            'users.edit',
            'users.view',
        ])
        ->and($roles['editor']->permissions->pluck('name')->sort()->values()->all())->toBe(['admin.access', 'media.delete', 'media.edit', 'media.upload', 'media.view', 'pages.create', 'pages.delete', 'pages.edit', 'pages.view'])
        ->and($roles['author']->permissions->pluck('name')->all())->toBe(['admin.access']);
});

test('running it again keeps permissions that administrators assigned', function () {
    seedRolesAndPermissions();
    Role::findByName('editor')->givePermissionTo('users.view');

    seedRolesAndPermissions();

    expect(Role::count())->toBe(4)
        ->and(Role::findByName('editor')->permissions->pluck('name')->sort()->values()->all())
        ->toBe(['admin.access', 'media.delete', 'media.edit', 'media.upload', 'media.view', 'pages.create', 'pages.delete', 'pages.edit', 'pages.view', 'users.view']);
});

test('the database seeder creates the initial super admin from the configuration', function () {
    config(['hata.admin.initial_admin' => [
        'name' => 'Owner',
        'email' => 'Owner@Example.com',
        'password' => 'secret-password',
    ]]);

    $this->seed(DatabaseSeeder::class);
    $this->seed(DatabaseSeeder::class);

    $owner = User::where('email', 'owner@example.com')->sole();
    expect($owner->name)->toBe('Owner')
        ->and($owner->hasRole('super-admin'))->toBeTrue()
        ->and($owner->email_verified_at)->not->toBeNull();
});

test('the database seeder creates no user when no initial admin is configured', function () {
    config(['hata.admin.initial_admin' => ['name' => null, 'email' => null, 'password' => null]]);

    $this->seed(DatabaseSeeder::class);

    expect(User::count())->toBe(0)
        ->and(Role::count())->toBe(4);
});

<?php

use App\Enums\SystemRole;
use App\Support\Permissions\PermissionRegistry;
use Illuminate\Support\Facades\Lang;

test('it loads the stage 1 permissions from the configuration', function () {
    expect(app(PermissionRegistry::class)->groups())->toBe([
        'admin' => ['admin.access'],
        'users' => ['users.view', 'users.create', 'users.edit', 'users.delete'],
        'roles' => ['roles.view', 'roles.create', 'roles.edit', 'roles.delete'],
    ]);
});

test('registering an existing group merges its actions', function () {
    $registry = (new PermissionRegistry)->register('pages', ['view'])->register('pages', ['view', 'edit']);

    expect($registry->all())->toBe(['pages.view', 'pages.edit']);
});

test('it rejects permission names outside the resource.action format', function () {
    (new PermissionRegistry)->register('Pages', ['view all']);
})->throws(InvalidArgumentException::class);

test('every registered permission and group has a label in every language', function () {
    foreach (array_keys(config('app.available_locales')) as $locale) {
        foreach (app(PermissionRegistry::class)->groups() as $group => $permissions) {
            expect(Lang::hasForLocale("permissions.groups.{$group}", $locale))->toBeTrue("permissions.groups.{$group} missing in {$locale}");

            foreach ($permissions as $permission) {
                expect(Lang::hasForLocale("permissions.items.{$permission}", $locale))->toBeTrue("permissions.items.{$permission} missing in {$locale}");
            }
        }
    }
});

test('every system role has a translated name in every language', function () {
    foreach (array_keys(config('app.available_locales')) as $locale) {
        foreach (SystemRole::cases() as $role) {
            expect(Lang::hasForLocale("roles.names.{$role->value}", $locale))->toBeTrue("roles.names.{$role->value} missing in {$locale}");
        }
    }
});

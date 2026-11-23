<?php

use Hata\Enums\SystemRole;
use Hata\Support\Permissions\PermissionRegistry;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Lang;

test('it loads the permissions from the configuration', function () {
    expect(app(PermissionRegistry::class)->groups())->toBe([
        'admin' => ['admin.access'],
        'users' => ['users.view', 'users.create', 'users.edit', 'users.delete'],
        'roles' => ['roles.view', 'roles.create', 'roles.edit', 'roles.delete'],
        'media' => ['media.view', 'media.upload', 'media.edit', 'media.delete'],
        'settings' => ['settings.edit'],
        'maintenance' => ['maintenance.run'],
        'pages' => ['pages.view', 'pages.create', 'pages.edit', 'pages.delete'],
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
        foreach (Arr::except(app(PermissionRegistry::class)->groups(), ['products']) as $group => $permissions) {
            expect(Lang::hasForLocale("hata::permissions.groups.{$group}", $locale))->toBeTrue("hata::permissions.groups.{$group} missing in {$locale}");

            foreach ($permissions as $permission) {
                expect(Lang::hasForLocale("hata::permissions.items.{$permission}", $locale))->toBeTrue("hata::permissions.items.{$permission} missing in {$locale}");
            }
        }
    }
});

test('every system role has a translated name in every language', function () {
    foreach (array_keys(config('app.available_locales')) as $locale) {
        foreach (SystemRole::cases() as $role) {
            expect(Lang::hasForLocale("hata::roles.names.{$role->value}", $locale))->toBeTrue("hata::roles.names.{$role->value} missing in {$locale}");
        }
    }
});

it('labels a permission from its registering owner', function () {
    trans()->addLines([
        'permissions.groups.shop' => 'Shop',
        'permissions.items.shop.view' => 'View orders',
    ], 'en', 'shop');

    $registry = (new PermissionRegistry)
        ->registerMany(['users' => ['view']], 'hata::permissions')
        ->register('shop', ['view'], 'shop::permissions');

    expect($registry->groupLabel('shop'))->toBe('Shop')
        ->and($registry->permissionLabel('shop.view'))->toBe('View orders')
        ->and($registry->permissionLabel('users.view'))->toBe(__('hata::permissions.items.users.view'))
        ->and($registry->permissionLabel('users.view'))->not->toBe('hata::permissions.items.users.view');
});

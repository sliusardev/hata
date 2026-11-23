<?php

use Hata\Enums\SystemRole;
use Hata\Support\Content\ContentTypes;
use Hata\Support\Content\Types\CategoriesType;
use Hata\Support\Content\Types\PagesType;
use Hata\Support\Content\Types\PostsType;
use Hata\Support\Content\Types\TagsType;
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
    $types = app(ContentTypes::class);

    foreach ([new PagesType, new PostsType, new CategoriesType, new TagsType] as $type) {
        if ($types->find($type->key()) === null) {
            $types->register($type);
        }
    }

    $registry = app(PermissionRegistry::class);

    foreach (array_keys(config('hata.admin.interface_locales')) as $locale) {
        foreach (Arr::except($registry->groups(), ['products']) as $group => $permissions) {
            expect(Lang::hasForLocale($registry->groupLabelKey($group), $locale))->toBeTrue("{$registry->groupLabelKey($group)} missing in {$locale}");

            foreach ($permissions as $permission) {
                expect(Lang::hasForLocale($registry->permissionLabelKey($permission), $locale))->toBeTrue("{$registry->permissionLabelKey($permission)} missing in {$locale}");
            }
        }
    }
});

test('every system role has a translated name in every language', function () {
    foreach (array_keys(config('hata.admin.interface_locales')) as $locale) {
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

it('keeps the first owner\'s prefix for a group and gives an added permission its own', function () {
    trans()->addLines(['permissions.items.users.export' => 'Export users'], 'en', 'shop');

    $registry = (new PermissionRegistry)
        ->register('users', ['view'], 'hata::permissions')
        ->register('users', ['export'], 'shop::permissions');

    expect($registry->groupLabelKey('users'))->toBe('hata::permissions.groups.users')
        ->and($registry->permissionLabelKey('users.view'))->toBe('hata::permissions.items.users.view')
        ->and($registry->permissionLabelKey('users.export'))->toBe('shop::permissions.items.users.export');
});

it('falls back to the site\'s own permissions labels when the owner has none', function () {
    trans()->addLines(['permissions.groups.blog' => 'Blog', 'permissions.items.blog.view' => 'View blog'], 'en');

    $registry = (new PermissionRegistry)->registerMany(['blog' => ['view']], 'hata::permissions');

    expect($registry->groupLabel('blog'))->toBe('Blog')
        ->and($registry->permissionLabel('blog.view'))->toBe('View blog');
});

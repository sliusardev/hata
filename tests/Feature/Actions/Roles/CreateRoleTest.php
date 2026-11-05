<?php

use App\Actions\Roles\CreateRole;
use App\Exceptions\ProtectedActionException;
use App\Models\Role;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;

beforeEach(fn () => seedRolesAndPermissions());

test('it creates a custom role with permissions', function () {
    $actor = User::factory()->withRole('admin')->create();

    $role = app(CreateRole::class)->handle($actor, [
        'label' => 'Moderator',
        'name' => 'moderator',
        'permissions' => ['admin.access', 'users.view'],
    ]);

    expect($role->is_system)->toBeFalse()
        ->and($role->label)->toBe('Moderator')
        ->and($role->displayName())->toBe('Moderator')
        ->and($role->permissions->pluck('name')->sort()->values()->all())->toBe(['admin.access', 'users.view']);
});

test('it requires the roles.create permission', function () {
    $actor = User::factory()->withRole('editor')->create();

    expect(fn () => app(CreateRole::class)->handle($actor, ['label' => 'X', 'name' => 'x', 'permissions' => []]))
        ->toThrow(AuthorizationException::class);

    expect(Role::where('name', 'x')->exists())->toBeFalse();
});

test('rule 5: it creates nothing when a permission is not held by the actor', function () {
    $actor = User::factory()->withPermissions(['admin.access', 'roles.create'])->create();

    expect(fn () => app(CreateRole::class)->handle($actor, [
        'label' => 'Sneaky',
        'name' => 'sneaky',
        'permissions' => ['admin.access', 'users.delete'],
    ]))->toThrow(ProtectedActionException::class, __('roles.errors.cannot_grant_permission', ['permission' => __('permissions.items.users.delete')]));

    expect(Role::where('name', 'sneaky')->exists())->toBeFalse();
});

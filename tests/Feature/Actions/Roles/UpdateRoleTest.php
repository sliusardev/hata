<?php

use App\Models\User;
use Hata\Actions\Roles\UpdateRole;
use Hata\Exceptions\ProtectedActionException;
use Hata\Models\Role;
use Illuminate\Auth\Access\AuthorizationException;

beforeEach(fn () => seedRolesAndPermissions());

function customRole(array $permissions = []): Role
{
    $role = Role::create(['name' => 'moderator', 'label' => 'Moderator', 'guard_name' => 'web']);
    $role->givePermissionTo($permissions);

    return $role;
}

test('it renames a custom role and replaces its permissions', function () {
    $actor = User::factory()->withRole('admin')->create();
    $role = customRole(['users.view']);

    app(UpdateRole::class)->handle($actor, $role, ['label' => 'Senior moderator', 'permissions' => ['roles.view']]);

    $role->refresh();
    expect($role->label)->toBe('Senior moderator')
        ->and($role->name)->toBe('moderator')
        ->and($role->permissions->pluck('name')->all())->toBe(['roles.view']);
});

test('it requires the roles.edit permission', function () {
    $actor = User::factory()->withPermissions(['admin.access', 'roles.view'])->create();
    $role = customRole(['users.view']);

    expect(fn () => app(UpdateRole::class)->handle($actor, $role, ['label' => 'X', 'permissions' => []]))
        ->toThrow(AuthorizationException::class);

    expect($role->fresh()->permissions->pluck('name')->all())->toBe(['users.view']);
});

test('rule 1: the super-admin role cannot be renamed or given permissions, even by a super admin', function () {
    $actor = User::factory()->withRole('super-admin')->create();
    $superAdminRole = Role::findByName('super-admin');

    expect(fn () => app(UpdateRole::class)->handle($actor, $superAdminRole, ['label' => 'Boss', 'permissions' => ['users.view']]))
        ->toThrow(ProtectedActionException::class, __('roles.errors.super_admin_locked'));

    $superAdminRole->refresh();
    expect($superAdminRole->label)->toBeNull()
        ->and($superAdminRole->permissions)->toBeEmpty();
});

test('rule 5: permissions the actor does not have cannot be added', function () {
    $actor = User::factory()->withPermissions(['admin.access', 'roles.edit', 'users.view'])->create();
    $role = customRole(['users.view']);

    expect(fn () => app(UpdateRole::class)->handle($actor, $role, ['label' => 'Moderator', 'permissions' => ['users.view', 'users.delete']]))
        ->toThrow(ProtectedActionException::class);

    expect($role->fresh()->permissions->pluck('name')->all())->toBe(['users.view']);
});

test('rule 5: permissions the actor has can be added', function () {
    $actor = User::factory()->withPermissions(['admin.access', 'roles.edit', 'users.view'])->create();
    $role = customRole();

    app(UpdateRole::class)->handle($actor, $role, ['label' => 'Moderator', 'permissions' => ['users.view']]);

    expect($role->fresh()->permissions->pluck('name')->all())->toBe(['users.view']);
});

test('rule 6: system roles keep their name but their permissions can change', function () {
    $actor = User::factory()->withRole('super-admin')->create();
    $editor = Role::findByName('editor');

    app(UpdateRole::class)->handle($actor, $editor, ['label' => 'Renamed', 'permissions' => ['admin.access', 'users.view']]);

    $editor->refresh();
    expect($editor->label)->toBeNull()
        ->and($editor->displayName())->toBe(__('roles.names.editor'))
        ->and($editor->permissions->pluck('name')->sort()->values()->all())->toBe(['admin.access', 'users.view']);
});

test('changing role permissions takes effect for its users immediately', function () {
    $actor = User::factory()->withRole('super-admin')->create();
    $member = User::factory()->withRole('editor')->create();
    expect($member->can('users.view'))->toBeFalse();

    app(UpdateRole::class)->handle($actor, Role::findByName('editor'), ['permissions' => ['admin.access', 'users.view']]);

    expect($member->fresh()->can('users.view'))->toBeTrue();
});

<?php

use App\Models\User;
use Hata\Actions\Roles\DeleteRole;
use Hata\Exceptions\ProtectedActionException;
use Hata\Models\Role;
use Illuminate\Auth\Access\AuthorizationException;

beforeEach(fn () => seedRolesAndPermissions());

test('it deletes an unused custom role', function () {
    $actor = User::factory()->withRole('admin')->create();
    $role = Role::create(['name' => 'moderator', 'label' => 'Moderator', 'guard_name' => 'web']);

    app(DeleteRole::class)->handle($actor, $role);

    $this->assertModelMissing($role);
});

test('it requires the roles.delete permission', function () {
    $actor = User::factory()->withPermissions(['admin.access', 'roles.edit'])->create();
    $role = Role::create(['name' => 'moderator', 'guard_name' => 'web']);

    expect(fn () => app(DeleteRole::class)->handle($actor, $role))->toThrow(AuthorizationException::class);

    $this->assertModelExists($role);
});

test('rule 1: the super-admin role cannot be deleted, even by a super admin', function () {
    $actor = User::factory()->withRole('super-admin')->create();
    $role = Role::findByName('super-admin');

    expect(fn () => app(DeleteRole::class)->handle($actor, $role))
        ->toThrow(ProtectedActionException::class, __('roles.errors.super_admin_locked'));

    $this->assertModelExists($role);
});

test('rule 6: system roles cannot be deleted', function () {
    $actor = User::factory()->withRole('super-admin')->create();
    $role = Role::findByName('author');

    expect(fn () => app(DeleteRole::class)->handle($actor, $role))
        ->toThrow(ProtectedActionException::class, __('roles.errors.cannot_delete_system'));

    $this->assertModelExists($role);
});

test('rule 7: a role assigned to users cannot be deleted', function () {
    $actor = User::factory()->withRole('super-admin')->create();
    $role = Role::create(['name' => 'moderator', 'guard_name' => 'web']);
    User::factory()->count(2)->withRole('moderator')->create();

    expect(fn () => app(DeleteRole::class)->handle($actor, $role))
        ->toThrow(ProtectedActionException::class, __('roles.errors.cannot_delete_in_use', ['users' => trans_choice('users.count', 2)]));

    $this->assertModelExists($role);
});

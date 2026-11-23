<?php

use App\Models\User;
use Hata\Actions\Users\SyncUserRoles;
use Hata\Exceptions\ProtectedActionException;
use Hata\Models\Role;

beforeEach(fn () => seedRolesAndPermissions());

function roleId(string $name): int
{
    return Role::findByName($name)->id;
}

test('it replaces the roles of a user', function () {
    $actor = User::factory()->withRole('super-admin')->create();
    $user = User::factory()->withRole('author')->create();

    app(SyncUserRoles::class)->handle($actor, $user, [roleId('editor')]);

    expect($user->fresh()->roles->pluck('name')->all())->toBe(['editor']);
});

test('rule 2: a non super admin cannot assign the super-admin role', function () {
    $actor = User::factory()->withRole('admin')->create();
    $user = User::factory()->create();

    expect(fn () => app(SyncUserRoles::class)->handle($actor, $user, [roleId('super-admin')]))
        ->toThrow(ProtectedActionException::class, __('hata::users.errors.cannot_manage_super_admin'));

    expect($user->fresh()->roles)->toBeEmpty();
});

test('rule 2: a non super admin cannot remove the super-admin role', function () {
    User::factory()->withRole('super-admin')->create();
    $superAdmin = User::factory()->withRole('super-admin')->create();
    $actor = User::factory()->withRole('admin')->create();

    expect(fn () => app(SyncUserRoles::class)->handle($actor, $superAdmin, []))
        ->toThrow(ProtectedActionException::class, __('hata::users.errors.cannot_manage_super_admin'));

    expect($superAdmin->fresh()->isSuperAdmin())->toBeTrue();
});

test('rule 2: a super admin can assign and remove the super-admin role', function () {
    $actor = User::factory()->withRole('super-admin')->create();
    $user = User::factory()->create();

    app(SyncUserRoles::class)->handle($actor, $user, [roleId('super-admin')]);
    expect($user->fresh()->isSuperAdmin())->toBeTrue();

    app(SyncUserRoles::class)->handle($actor, $user, []);
    expect($user->fresh()->isSuperAdmin())->toBeFalse();
});

test('rule 3: the super-admin role cannot be removed from the last super admin', function () {
    $lastSuperAdmin = User::factory()->withRole('super-admin')->create();
    $actor = User::factory()->withRole('admin')->create();

    expect(fn () => app(SyncUserRoles::class)->handle($actor, $lastSuperAdmin, [roleId('admin')]))
        ->toThrow(ProtectedActionException::class, __('hata::users.errors.cannot_remove_last_super_admin'));

    expect($lastSuperAdmin->fresh()->roles->pluck('name')->all())->toBe(['super-admin']);
});

test('rule 4: users cannot change their own roles', function () {
    $actor = User::factory()->withRole('super-admin')->create();
    User::factory()->withRole('super-admin')->create();

    expect(fn () => app(SyncUserRoles::class)->handle($actor, $actor, [roleId('super-admin'), roleId('editor')]))
        ->toThrow(ProtectedActionException::class, __('hata::users.errors.cannot_change_own_roles'));

    expect($actor->fresh()->roles->pluck('name')->all())->toBe(['super-admin']);
});

test('rule 4: submitting their unchanged roles is allowed', function () {
    $actor = User::factory()->withRole('admin')->create();

    app(SyncUserRoles::class)->handle($actor, $actor, [roleId('admin')]);

    expect($actor->fresh()->roles->pluck('name')->all())->toBe(['admin']);
});

test('rule 5: users can only assign roles whose permissions they all have', function () {
    $actor = User::factory()->withRole('editor')->withPermissions(['users.edit'])->create();
    $user = User::factory()->create();

    expect(fn () => app(SyncUserRoles::class)->handle($actor, $user, [roleId('admin')]))
        ->toThrow(ProtectedActionException::class, __('hata::users.errors.cannot_assign_role', ['role' => __('hata::roles.names.admin')]));

    expect($user->fresh()->roles)->toBeEmpty();

    app(SyncUserRoles::class)->handle($actor, $user, [roleId('author')]);

    expect($user->fresh()->roles->pluck('name')->all())->toBe(['author']);
});

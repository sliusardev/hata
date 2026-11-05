<?php

use App\Actions\Users\DeleteUser;
use App\Exceptions\ProtectedActionException;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;

beforeEach(fn () => seedRolesAndPermissions());

test('it deletes a user', function () {
    $actor = User::factory()->withRole('admin')->create();
    $user = User::factory()->withRole('editor')->create();

    app(DeleteUser::class)->handle($actor, $user);

    $this->assertModelMissing($user);
    $this->assertDatabaseMissing('model_has_roles', ['model_id' => $user->id, 'model_type' => User::class]);
});

test('it requires the users.delete permission', function () {
    $actor = User::factory()->withRole('editor')->create();
    $user = User::factory()->create();

    expect(fn () => app(DeleteUser::class)->handle($actor, $user))->toThrow(AuthorizationException::class);

    $this->assertModelExists($user);
});

test('rule 4: users cannot delete themselves', function () {
    $actor = User::factory()->withRole('super-admin')->create();
    User::factory()->withRole('super-admin')->create();

    expect(fn () => app(DeleteUser::class)->handle($actor, $actor))
        ->toThrow(ProtectedActionException::class, __('users.errors.cannot_delete_self'));

    $this->assertModelExists($actor);
});

test('rule 3: the last super admin cannot be deleted', function () {
    $lastSuperAdmin = User::factory()->withRole('super-admin')->create();
    $actor = User::factory()->withRole('admin')->create();

    expect(fn () => app(DeleteUser::class)->handle($actor, $lastSuperAdmin))
        ->toThrow(ProtectedActionException::class, __('users.errors.cannot_delete_last_super_admin'));

    $this->assertModelExists($lastSuperAdmin);
});

test('only super admins can delete super admins', function () {
    $superAdmin = User::factory()->withRole('super-admin')->create();
    User::factory()->withRole('super-admin')->create();
    $actor = User::factory()->withRole('admin')->create();

    expect(fn () => app(DeleteUser::class)->handle($actor, $superAdmin))
        ->toThrow(ProtectedActionException::class, __('users.errors.cannot_manage_super_admin'));

    $this->assertModelExists($superAdmin);
});

test('a super admin can delete another super admin when one remains', function () {
    $actor = User::factory()->withRole('super-admin')->create();
    $otherSuperAdmin = User::factory()->withRole('super-admin')->create();

    app(DeleteUser::class)->handle($actor, $otherSuperAdmin);

    $this->assertModelMissing($otherSuperAdmin);
});

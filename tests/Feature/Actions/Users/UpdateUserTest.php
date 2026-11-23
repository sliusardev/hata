<?php

use App\Models\User;
use Hata\Actions\Users\UpdateUser;
use Hata\Exceptions\ProtectedActionException;
use Hata\Models\Role;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Hash;

beforeEach(fn () => seedRolesAndPermissions());

test('it updates the details and roles without changing the password', function () {
    $actor = User::factory()->withRole('admin')->create();
    $user = User::factory()->withRole('author')->create(['password' => 'old-password']);

    app(UpdateUser::class)->handle($actor, $user, [
        'name' => 'New Name',
        'email' => 'NEW@example.com',
        'locale' => 'uk',
        'password' => null,
        'roles' => [Role::findByName('editor')->id],
    ]);

    $user->refresh();
    expect($user->name)->toBe('New Name')
        ->and($user->email)->toBe('new@example.com')
        ->and($user->locale)->toBe('uk')
        ->and(Hash::check('old-password', $user->password))->toBeTrue()
        ->and($user->roles->pluck('name')->all())->toBe(['editor']);
});

test('it changes the password when one is given', function () {
    $actor = User::factory()->withRole('admin')->create();
    $user = User::factory()->create();

    app(UpdateUser::class)->handle($actor, $user, [
        'name' => $user->name,
        'email' => $user->email,
        'locale' => 'en',
        'password' => 'brand-new-password',
    ]);

    expect(Hash::check('brand-new-password', $user->fresh()->password))->toBeTrue();
});

test('it leaves roles untouched when they are not submitted', function () {
    $actor = User::factory()->withRole('admin')->create();

    app(UpdateUser::class)->handle($actor, $actor, [
        'name' => 'Renamed',
        'email' => $actor->email,
        'locale' => 'en',
        'password' => null,
    ]);

    expect($actor->fresh()->roles->pluck('name')->all())->toBe(['admin']);
});

test('it requires the users.edit permission', function () {
    $actor = User::factory()->withRole('editor')->create();
    $user = User::factory()->create(['name' => 'Original']);

    expect(fn () => app(UpdateUser::class)->handle($actor, $user, [
        'name' => 'Changed', 'email' => $user->email, 'locale' => 'en', 'password' => null,
    ]))->toThrow(AuthorizationException::class);

    expect($user->fresh()->name)->toBe('Original');
});

test('only super admins can edit super admins', function () {
    $superAdmin = User::factory()->withRole('super-admin')->create(['name' => 'Original']);
    $actor = User::factory()->withRole('admin')->create();

    expect(fn () => app(UpdateUser::class)->handle($actor, $superAdmin, [
        'name' => 'Changed', 'email' => 'attacker@example.com', 'locale' => 'en', 'password' => 'taken-over',
    ]))->toThrow(ProtectedActionException::class, __('users.errors.cannot_manage_super_admin'));

    expect($superAdmin->fresh()->name)->toBe('Original');
});

test('a failed role change rolls back the other changes', function () {
    $actor = User::factory()->withRole('admin')->create();
    $user = User::factory()->create(['name' => 'Original']);

    expect(fn () => app(UpdateUser::class)->handle($actor, $user, [
        'name' => 'Changed', 'email' => $user->email, 'locale' => 'en', 'password' => null,
        'roles' => [Role::findByName('super-admin')->id],
    ]))->toThrow(ProtectedActionException::class);

    expect($user->fresh()->name)->toBe('Original');
});

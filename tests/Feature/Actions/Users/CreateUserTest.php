<?php

use App\Models\User;
use Hata\Actions\Users\CreateUser;
use Hata\Exceptions\ProtectedActionException;
use Hata\Models\Role;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Hash;

beforeEach(fn () => seedRolesAndPermissions());

/**
 * @return array{name: string, email: string, password: string, locale: string, roles: list<int>, email_verified: bool}
 */
function newUserData(array $overrides = []): array
{
    return [
        'name' => 'Олена Петренко',
        'email' => 'Olena@Example.com',
        'password' => 'secret-password',
        'locale' => 'uk',
        'roles' => [],
        'email_verified' => true,
        ...$overrides,
    ];
}

test('it creates a user with roles', function () {
    $actor = User::factory()->withRole('admin')->create();

    $user = app(CreateUser::class)->handle($actor, newUserData(['roles' => [Role::findByName('editor')->id]]));

    expect($user->name)->toBe('Олена Петренко')
        ->and($user->email)->toBe('olena@example.com')
        ->and($user->locale)->toBe('uk')
        ->and($user->email_verified_at)->not->toBeNull()
        ->and(Hash::check('secret-password', $user->password))->toBeTrue()
        ->and($user->roles->pluck('name')->all())->toBe(['editor']);
});

test('it leaves the email unverified when not requested', function () {
    $actor = User::factory()->withRole('admin')->create();

    $user = app(CreateUser::class)->handle($actor, newUserData(['email_verified' => false]));

    expect($user->email_verified_at)->toBeNull();
});

test('it requires the users.create permission', function () {
    $actor = User::factory()->withRole('editor')->create();

    expect(fn () => app(CreateUser::class)->handle($actor, newUserData()))->toThrow(AuthorizationException::class);

    expect(User::where('email', 'olena@example.com')->exists())->toBeFalse();
});

test('it creates nothing when a role cannot be assigned', function () {
    $actor = User::factory()->withRole('admin')->create();

    expect(fn () => app(CreateUser::class)->handle($actor, newUserData(['roles' => [Role::findByName('super-admin')->id]])))
        ->toThrow(ProtectedActionException::class);

    expect(User::where('email', 'olena@example.com')->exists())->toBeFalse();
});

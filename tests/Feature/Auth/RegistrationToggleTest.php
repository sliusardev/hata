<?php

use App\Actions\Fortify\CreateNewUser;
use App\Support\Settings\SiteSettings;

test('registration is disabled by default', function () {
    expect(config('admin.registration_enabled'))->toBeFalse();

    $this->get('/register')->assertNotFound();
    $this->get(route('login'))->assertOk()->assertDontSee(__('auth.login.sign_up'));
});

test('registered users get no role and no admin access', function () {
    $user = app(CreateNewUser::class)->create([
        'name' => 'New User',
        'email' => 'new@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ]);

    expect($user->roles)->toBeEmpty()
        ->and($user->can('admin.access'))->toBeFalse();
});

test('turning registration on in the site settings opens the sign-up page', function () {
    app(SiteSettings::class)->put(['registration_enabled' => true]);

    $this->get(route('register'))->assertOk();
    $this->get(route('login'))->assertSee(__('auth.login.sign_up'));
});

test('registered users get the default role from the site settings', function () {
    seedRolesAndPermissions();
    app(SiteSettings::class)->put(['default_role' => 'author']);

    $user = app(CreateNewUser::class)->create([
        'name' => 'New User',
        'email' => 'new@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ]);

    expect($user->roles->pluck('name')->all())->toBe(['author']);
});

test('super-admin is never given to registered users', function () {
    seedRolesAndPermissions();
    config(['admin.default_role' => 'super-admin']);

    $user = app(CreateNewUser::class)->create([
        'name' => 'New User',
        'email' => 'new@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ]);

    expect($user->roles)->toBeEmpty();
});

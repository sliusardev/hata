<?php

use App\Actions\Fortify\CreateNewUser;

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

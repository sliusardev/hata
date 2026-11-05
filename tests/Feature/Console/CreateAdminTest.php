<?php

use App\Models\User;
use Illuminate\Support\Facades\Hash;

test('it creates a super admin from options without interaction', function () {
    $this->artisan('admin:create', [
        '--name' => 'Ada Lovelace',
        '--email' => 'Ada@Example.com',
        '--password' => 'secret-password',
        '--no-interaction' => true,
    ])->assertSuccessful();

    $user = User::where('email', 'ada@example.com')->sole();
    expect($user->name)->toBe('Ada Lovelace')
        ->and(Hash::check('secret-password', $user->password))->toBeTrue()
        ->and($user->hasRole('super-admin'))->toBeTrue()
        ->and($user->email_verified_at)->not->toBeNull();
});

test('it asks for missing values interactively', function () {
    $this->artisan('admin:create')
        ->expectsQuestion('Name', 'Ada Lovelace')
        ->expectsQuestion('Email', 'ada@example.com')
        ->expectsQuestion('Password', 'secret-password')
        ->assertSuccessful();

    expect(User::where('email', 'ada@example.com')->sole()->hasRole('super-admin'))->toBeTrue();
});

test('it fails without interaction when values are missing', function () {
    $this->artisan('admin:create', ['--email' => 'ada@example.com', '--no-interaction' => true])
        ->assertFailed();

    expect(User::count())->toBe(0);
});

test('it rejects an email that is already taken', function () {
    User::factory()->create(['email' => 'ada@example.com']);

    $this->artisan('admin:create', [
        '--name' => 'Ada Lovelace',
        '--email' => 'ada@example.com',
        '--password' => 'secret-password',
        '--no-interaction' => true,
    ])->assertFailed();

    expect(User::count())->toBe(1)
        ->and(User::first()->hasRole('super-admin'))->toBeFalse();
});

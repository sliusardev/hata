<?php

use App\Models\User;
use App\Support\Settings\SiteSettings;
use Illuminate\Support\Carbon;

test('authenticated users get the interface in their own language', function () {
    $user = User::factory()->create(['locale' => 'uk']);

    $response = $this->actingAs($user)->get(route('profile.edit'));

    $response->assertOk()
        ->assertSee('<html lang="uk"', escape: false)
        ->assertSee('Налаштування профілю');
});

test('users without a saved language get the default application locale', function () {
    config(['app.locale' => 'en']);
    $user = User::factory()->create(['locale' => null]);

    $response = $this->actingAs($user)->get(route('profile.edit'));

    $response->assertOk()
        ->assertSee('<html lang="en"', escape: false)
        ->assertSee('Profile settings');
});

test('an unsupported saved language falls back to the default application locale', function () {
    config(['app.locale' => 'en']);
    $user = User::factory()->create(['locale' => 'xx']);

    $response = $this->actingAs($user)->get(route('profile.edit'));

    $response->assertOk()->assertSee('<html lang="en"', escape: false);
});

test('guests get the default application locale', function () {
    app(SiteSettings::class)->put(['default_locale' => 'uk']);

    $response = $this->get(route('login'));

    $response->assertOk()
        ->assertSee('<html lang="uk"', escape: false)
        ->assertSee('Увійдіть в обліковий запис');
});

test('dates are formatted in the user language', function () {
    $user = User::factory()->create(['locale' => 'uk']);

    $this->actingAs($user)->get(route('profile.edit'));

    expect(Carbon::parse('2026-03-01')->translatedFormat('F'))->toBe('березень');
});

test('vendor messages passed as English literals are translated', function () {
    $user = User::factory()->create(['locale' => 'uk']);

    $response = $this->actingAs($user)->post(route('password.confirm.store'), [
        'password' => 'wrong-password',
    ]);

    $response->assertSessionHasErrors(['password' => 'Вказано неправильний пароль.']);
});

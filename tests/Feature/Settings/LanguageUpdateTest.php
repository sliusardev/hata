<?php

use App\Models\User;
use Hata\Support\Toast;
use Livewire\Livewire;

test('language page is displayed', function () {
    $this->actingAs(User::factory()->create());

    $this->get(route('language.edit'))->assertOk();
});

test('guests are redirected from the language page to the login page', function () {
    $this->get(route('language.edit'))->assertRedirect(route('login'));
});

test('language can be updated', function () {
    $user = User::factory()->create(['locale' => 'en']);
    $this->actingAs($user);

    $response = Livewire::test('pages::settings.language')
        ->set('locale', 'uk')
        ->call('updateLocale');

    $response->assertHasNoErrors()->assertRedirect(route('language.edit'));
    expect($user->refresh()->locale)->toBe('uk')
        ->and(session(Toast::SESSION_KEY))->toBe(['text' => 'Мову змінено.', 'variant' => 'success']);
});

test('the interface switches to the new language right after saving', function () {
    $user = User::factory()->create(['locale' => 'en']);
    $this->actingAs($user);

    Livewire::test('pages::settings.language')->set('locale', 'uk')->call('updateLocale');

    $this->get(route('language.edit'))
        ->assertOk()
        ->assertSee('<html lang="uk"', escape: false)
        ->assertSee('Мова інтерфейсу')
        ->assertSee('Мову змінено.');
});

test('language must be one of the available locales', function () {
    $user = User::factory()->create(['locale' => 'en']);
    $this->actingAs($user);

    $response = Livewire::test('pages::settings.language')
        ->set('locale', 'xx')
        ->call('updateLocale');

    $response->assertHasErrors(['locale' => 'in']);
    expect($user->refresh()->locale)->toBe('en');
});

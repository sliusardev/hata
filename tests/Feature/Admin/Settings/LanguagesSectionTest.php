<?php

use App\Models\User;
use Hata\Models\Setting;
use Hata\Support\Toast;
use Livewire\Livewire;

beforeEach(fn () => seedRolesAndPermissions());

it('saves only the interface default and redirects back to its tab with a message', function () {
    $this->actingAs(User::factory()->withRole('admin')->create());

    Livewire::test('hata::admin.settings.sections.languages')
        ->assertSet('values.default_locale', 'en')
        ->set('values.default_locale', 'uk')
        ->call('save')
        ->assertHasNoErrors()
        ->assertRedirect(route('admin.settings.edit', ['tab' => 'languages']));

    expect(Setting::query()->pluck('value', 'key')->sortKeys()->all())->toBe(['default_locale' => 'uk'])
        ->and(session(Toast::SESSION_KEY)['text'])->toBe(__('hata::site_settings.messages.saved'));
});

it('validates the interface default language', function () {
    $this->actingAs(User::factory()->withRole('admin')->create());

    Livewire::test('hata::admin.settings.sections.languages')
        ->set('values.default_locale', 'fr')
        ->call('save')
        ->assertHasErrors(['values.default_locale']);

    expect(Setting::count())->toBe(0);
});

it('is forbidden without settings.edit, on mount and on every action', function () {
    $this->actingAs(User::factory()->withRole('admin')->create());
    $add = Livewire::test('hata::admin.settings.sections.languages');
    $confirm = Livewire::test('hata::admin.settings.sections.languages');

    $this->actingAs(User::factory()->withRole('editor')->create());

    $add->call('addLanguage', 'de')->assertForbidden();
    $confirm->call('confirmLanguage', 'remove', 'en')->assertForbidden();

    Livewire::test('hata::admin.settings.sections.languages')->assertForbidden();
});

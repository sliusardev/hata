<?php

use App\Models\User;
use Hata\Models\Setting;
use Hata\Support\Toast;
use Livewire\Livewire;

beforeEach(fn () => seedRolesAndPermissions());

it('saves only the site name and the timezone and redirects back to its tab with a message', function () {
    $this->actingAs(User::factory()->withRole('admin')->create());

    Livewire::test('hata::admin.settings.sections.site')
        ->set('values.site_name', 'Hata')
        ->set('values.timezone', 'Europe/Kyiv')
        ->call('save')
        ->assertHasNoErrors()
        ->assertRedirect(route('admin.settings.edit', ['tab' => 'site']));

    expect(Setting::query()->pluck('value', 'key')->sortKeys()->all())->toBe(['site_name' => 'Hata', 'timezone' => 'Europe/Kyiv'])
        ->and(session(Toast::SESSION_KEY)['text'])->toBe(__('hata::site_settings.messages.saved'))
        ->and(config('app.name'))->toBe('Hata')
        ->and(config('hata.admin.timezone'))->toBe('Europe/Kyiv');
});

it('keeps an untouched site name following config', function () {
    $this->actingAs(User::factory()->withRole('admin')->create());

    Livewire::test('hata::admin.settings.sections.site')
        ->set('values.timezone', 'Europe/Kyiv')
        ->call('save')
        ->assertHasNoErrors();

    expect(Setting::query()->pluck('value', 'key')->all())->toBe(['timezone' => 'Europe/Kyiv']);
});

it('validates the site name and the timezone', function () {
    $this->actingAs(User::factory()->withRole('admin')->create());

    Livewire::test('hata::admin.settings.sections.site')
        ->set('values.site_name', '')
        ->set('values.timezone', 'Mars/Olympus')
        ->call('save')
        ->assertHasErrors(['values.site_name', 'values.timezone']);

    expect(Setting::count())->toBe(0);
});

it('is refused without the settings.edit permission', function () {
    $this->actingAs(User::factory()->withPermissions(['admin.access'])->create());

    Livewire::test('hata::admin.settings.sections.site')->assertForbidden();
});

it('a successful save marks the form clean for the leave-page warning', function () {
    $this->actingAs(User::factory()->withRole('admin')->create());

    Livewire::test('hata::admin.settings.sections.site')
        ->set('values.site_name', 'Renamed')
        ->call('save')
        ->assertSet('saved.site_name', 'Renamed');
});

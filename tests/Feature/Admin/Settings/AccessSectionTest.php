<?php

use App\Models\User;
use Hata\Models\Role;
use Hata\Models\Setting;
use Hata\Support\Toast;
use Livewire\Livewire;

beforeEach(fn () => seedRolesAndPermissions());

it('saves only the access settings and redirects back to its tab with a message', function () {
    $this->actingAs(User::factory()->withRole('admin')->create());

    Livewire::test('hata::admin.settings.sections.access')
        ->set('values.registration_enabled', true)
        ->set('values.default_role', 'author')
        ->call('save')
        ->assertHasNoErrors()
        ->assertRedirect(route('admin.settings.edit', ['tab' => 'access']));

    expect(Setting::query()->pluck('value', 'key')->sortKeys()->all())->toBe(['default_role' => 'author', 'registration_enabled' => true])
        ->and(session(Toast::SESSION_KEY)['text'])->toBe(__('hata::site_settings.messages.saved'))
        ->and(config('hata.admin.registration_enabled'))->toBeTrue();
});

it('super-admin cannot be the role of new users', function () {
    $this->actingAs(User::factory()->withRole('super-admin')->create());

    Livewire::test('hata::admin.settings.sections.access')
        ->set('values.default_role', 'super-admin')
        ->call('save')
        ->assertHasErrors(['values.default_role' => 'not_in']);
});

it('the role of new users must be one the actor may assign', function () {
    $this->actingAs(User::factory()->withPermissions(['admin.access', 'settings.edit'])->create());
    Role::create(['name' => 'manager', 'guard_name' => 'web'])->givePermissionTo('users.delete');

    Livewire::test('hata::admin.settings.sections.access')
        ->set('values.default_role', 'manager')
        ->call('save')
        ->assertHasErrors(['values.default_role'])
        ->assertNoRedirect()
        ->assertSee(__('hata::site_settings.messages.role_unavailable'));

    expect(Setting::count())->toBe(0);
});

it('warns when the role of new users opens the admin panel', function () {
    $this->actingAs(User::factory()->withRole('admin')->create());

    Livewire::test('hata::admin.settings.sections.access')
        ->set('values.default_role', 'editor')
        ->assertSee(__('hata::site_settings.access.admin_access_warning'))
        ->set('values.default_role', '')
        ->assertDontSee(__('hata::site_settings.access.admin_access_warning'));
});

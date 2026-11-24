<?php

use App\Models\User;
use Hata\Models\Setting;
use Hata\Support\Settings\SiteSettings;
use Hata\Support\Toast;
use Livewire\Livewire;

beforeEach(fn () => seedRolesAndPermissions());

/**
 * A valid form for the page, with the server mailer.
 *
 * @return array<string, mixed>
 */
function siteSettingsInput(array $overrides = []): array
{
    return [
        'form.site_name' => 'Hata',
        'form.default_locale' => 'uk',
        'form.timezone' => 'Europe/Kyiv',
        'form.registration_enabled' => true,
        'form.default_role' => '',
        'form.mail_mailer' => 'server',
        'form.mail_from_address' => 'site@example.com',
        'form.mail_from_name' => 'Hata site',
        ...$overrides,
    ];
}

test('it returns 403 without the settings.edit permission', function () {
    $this->actingAs(User::factory()->withPermissions(['admin.access', 'users.view'])->create());

    $this->get(route('admin.settings.edit'))->assertForbidden();
});

test('it is displayed with the settings.edit permission', function () {
    $this->actingAs(User::factory()->withPermissions(['admin.access', 'settings.edit'])->create());

    $this->get(route('admin.settings.edit'))
        ->assertOk()
        ->assertSee(__('hata::site_settings.mail.heading'));
});

test('the sidebar links to the page only with the settings.edit permission', function () {
    $this->actingAs(User::factory()->withPermissions(['admin.access', 'users.view'])->create());
    $this->get(route('admin.users.index'))->assertDontSee('href="'.route('admin.settings.edit', absolute: false).'"', false);

    $this->actingAs(User::factory()->withPermissions(['admin.access', 'settings.edit'])->create());
    $this->get(route('admin.dashboard'))->assertSee('href="'.route('admin.settings.edit', absolute: false).'"', false);
});

test('each tab saves only its own settings and redirects back to it with a message', function (string $tab, array $expected) {
    $this->actingAs(User::factory()->withRole('admin')->create());

    Livewire::test('hata::admin.settings.edit')
        ->set(siteSettingsInput(['form.default_role' => 'author']))
        ->set('tab', $tab)
        ->call('save')
        ->assertHasNoErrors()
        ->assertRedirect(route('admin.settings.edit', ['tab' => $tab]));

    expect(Setting::query()->pluck('value', 'key')->sortKeys()->all())->toBe($expected)
        ->and(session(Toast::SESSION_KEY)['text'])->toBe(__('hata::site_settings.messages.saved'));
})->with([
    'languages' => ['languages', ['default_locale' => 'uk']],
]);

test('the tab comes from the query string and falls back to general', function () {
    $this->actingAs(User::factory()->withRole('admin')->create());

    Livewire::withQueryParams(['tab' => 'mail'])->test('hata::admin.settings.edit')
        ->assertSet('tab', 'mail')
        ->assertSee(__('hata::site_settings.mail.send_test'));

    Livewire::withQueryParams(['tab' => 'nonsense'])->test('hata::admin.settings.edit')
        ->assertSet('tab', 'general')
        ->assertDontSee(__('hata::site_settings.mail.send_test'));
});

test('it validates the interface default language', function () {
    $this->actingAs(User::factory()->withRole('admin')->create());

    Livewire::test('hata::admin.settings.edit')
        ->set(siteSettingsInput(['form.default_locale' => 'fr']))
        ->set('tab', 'languages')
        ->call('save')
        ->assertHasErrors(['form.default_locale']);

    expect(Setting::count())->toBe(0);
});

test('switching tabs without changes opens the tab right away', function () {
    $this->actingAs(User::factory()->withRole('admin')->create());

    Livewire::test('hata::admin.settings.edit')
        ->call('switchTab', 'mail')
        ->assertSet('tab', 'mail')
        ->assertSet('showUnsavedModal', false);
});

test('switching tabs with unsaved changes asks first and can discard them', function () {
    $this->actingAs(User::factory()->withRole('admin')->create());
    $originalName = config('app.name');

    Livewire::test('hata::admin.settings.edit')
        ->set('form.site_name', 'Changed')
        ->call('switchTab', 'mail')
        ->assertSet('tab', 'general')
        ->assertSet('showUnsavedModal', true)
        ->assertSee(__('hata::site_settings.unsaved.body', ['tab' => __('hata::site_settings.site.heading')]))
        ->call('discardChangesAndSwitch')
        ->assertSet('tab', 'mail')
        ->assertSet('showUnsavedModal', false)
        ->assertSet('form.site_name', $originalName);

    expect(Setting::count())->toBe(0);
});

test('a typed SMTP password counts as an unsaved change', function () {
    $this->actingAs(User::factory()->withRole('admin')->create());
    app(SiteSettings::class)->put(['mail_mailer' => 'smtp', 'mail_host' => 'smtp.example.com', 'mail_port' => 587, 'mail_scheme' => 'smtp']);

    Livewire::test('hata::admin.settings.edit', ['tab' => 'mail'])
        ->set('form.mail_password', 'secret')
        ->call('switchTab', 'general')
        ->assertSet('showUnsavedModal', true)
        ->call('discardChangesAndSwitch')
        ->assertSet('form.mail_password', '');
});

test('it saves a custom color scheme and every page applies it', function () {
    $this->actingAs(User::factory()->withRole('admin')->create());

    Livewire::test('hata::admin.settings.edit', ['tab' => 'colors'])
        ->set('form.color_scheme', 'custom')
        ->set('form.color_accent', '#8A1C7C')
        ->set('form.color_gray', 'stone')
        ->set('form.color_tint_sidebar', false)
        ->call('save')
        ->assertHasNoErrors();

    expect(Setting::query()->pluck('value', 'key')->only(['color_scheme', 'color_accent', 'color_gray', 'color_tint_sidebar'])->all())
        ->toBe(['color_scheme' => 'custom', 'color_accent' => '#8a1c7c', 'color_gray' => 'stone', 'color_tint_sidebar' => false]);

    $this->get(route('admin.dashboard'))->assertSee('<style id="color-scheme">:root{', false)->assertSee('--color-accent:#8a1c7c', false);
});

test('a custom scheme needs a valid hex color', function (string $color) {
    $this->actingAs(User::factory()->withRole('admin')->create());

    Livewire::test('hata::admin.settings.edit', ['tab' => 'colors'])
        ->set('form.color_scheme', 'custom')
        ->set('form.color_accent', $color)
        ->call('save')
        ->assertHasErrors('form.color_accent');

    expect(Setting::count())->toBe(0);
})->with(['', 'blue', '#12345', '#12345g']);

test('the colors tab previews the chosen scheme before it is saved', function () {
    $this->actingAs(User::factory()->withRole('admin')->create());

    Livewire::test('hata::admin.settings.edit', ['tab' => 'colors'])
        ->set('form.color_scheme', 'rose')
        ->assertSee('--color-accent:#e11d48', false);

    expect(Setting::count())->toBe(0);
});

<?php

use App\Models\User;
use Hata\Models\Role;
use Hata\Models\Setting;
use Hata\Support\Settings\SiteSettings;
use Hata\Support\Toast;
use Illuminate\Support\Facades\Crypt;
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
    'general' => ['general', ['site_name' => 'Hata', 'timezone' => 'Europe/Kyiv']],
    'languages' => ['languages', ['default_locale' => 'uk']],
    'access' => ['access', ['default_role' => 'author', 'registration_enabled' => true]],
    'mail' => ['mail', ['mail_from_address' => 'site@example.com', 'mail_from_name' => 'Hata site']],
]);

test('saved settings are applied to the config', function () {
    $this->actingAs(User::factory()->withRole('admin')->create());

    Livewire::test('hata::admin.settings.edit')
        ->set(siteSettingsInput())
        ->call('save')
        ->set('tab', 'access')
        ->call('save');

    expect(config('app.name'))->toBe('Hata')
        ->and(config('hata.admin.timezone'))->toBe('Europe/Kyiv')
        ->and(config('hata.admin.registration_enabled'))->toBeTrue();
});

test('the tab comes from the query string and falls back to general', function () {
    $this->actingAs(User::factory()->withRole('admin')->create());

    Livewire::withQueryParams(['tab' => 'mail'])->test('hata::admin.settings.edit')
        ->assertSet('tab', 'mail')
        ->assertSee(__('hata::site_settings.mail.send_test'));

    Livewire::withQueryParams(['tab' => 'nonsense'])->test('hata::admin.settings.edit')
        ->assertSet('tab', 'general')
        ->assertDontSee(__('hata::site_settings.mail.send_test'));
});

test('it stores the SMTP password encrypted and keeps it when the field is left empty', function () {
    $this->actingAs(User::factory()->withRole('admin')->create());
    $smtp = [
        'form.mail_mailer' => 'smtp',
        'form.mail_host' => 'smtp.example.com',
        'form.mail_port' => '587',
        'form.mail_scheme' => 'smtp',
        'form.mail_username' => 'mailer',
    ];

    Livewire::test('hata::admin.settings.edit')
        ->set('tab', 'mail')
        ->set(siteSettingsInput([...$smtp, 'form.mail_password' => 'secret']))
        ->call('save')
        ->assertHasNoErrors();

    Livewire::test('hata::admin.settings.edit')
        ->set('tab', 'mail')
        ->assertSet('form.mail_password', '')
        ->set(siteSettingsInput([...$smtp, 'form.mail_host' => 'smtp2.example.com']))
        ->call('save')
        ->assertHasNoErrors();

    $stored = Setting::query()->where('key', 'mail_password')->value('value');
    expect($stored)->not->toBe('secret')
        ->and(Crypt::decryptString($stored))->toBe('secret')
        ->and(config('mail.default'))->toBe('smtp')
        ->and(config('mail.mailers.smtp.host'))->toBe('smtp2.example.com')
        ->and(config('mail.mailers.smtp.password'))->toBe('secret');
});

test('switching back to the server mailer removes the SMTP settings', function () {
    $this->actingAs(User::factory()->withRole('admin')->create());
    app(SiteSettings::class)->put(['mail_mailer' => 'smtp', 'mail_host' => 'smtp.example.com', 'mail_password' => 'secret']);

    Livewire::test('hata::admin.settings.edit')
        ->assertSet('form.mail_mailer', 'smtp')
        ->set('tab', 'mail')
        ->set(siteSettingsInput())
        ->call('save')
        ->assertHasNoErrors();

    expect(Setting::query()->whereIn('key', ['mail_mailer', 'mail_host', 'mail_password'])->exists())->toBeFalse()
        ->and(config('mail.default'))->toBe('array');
});

test('it requires the SMTP host, port and encryption only for the SMTP mailer', function () {
    $this->actingAs(User::factory()->withRole('admin')->create());

    Livewire::test('hata::admin.settings.edit')
        ->set('tab', 'mail')
        ->set(siteSettingsInput(['form.mail_mailer' => 'smtp', 'form.mail_host' => '', 'form.mail_port' => '', 'form.mail_scheme' => '']))
        ->call('save')
        ->assertHasErrors(['form.mail_host' => 'required_if', 'form.mail_port' => 'required_if', 'form.mail_scheme' => 'required_if']);
});

test('it validates the general settings', function () {
    $this->actingAs(User::factory()->withRole('admin')->create());

    Livewire::test('hata::admin.settings.edit')
        ->set(siteSettingsInput([
            'form.site_name' => '',
            'form.default_locale' => 'fr',
            'form.timezone' => 'Mars/Olympus',
        ]))
        ->call('save')
        ->assertHasErrors(['form.site_name', 'form.timezone'])
        ->set('tab', 'languages')
        ->call('save')
        ->assertHasErrors(['form.default_locale']);

    expect(Setting::count())->toBe(0);
});

test('it validates the sender address on the mail tab only', function () {
    $this->actingAs(User::factory()->withRole('admin')->create());

    Livewire::test('hata::admin.settings.edit')
        ->set(siteSettingsInput(['form.mail_from_address' => 'not-an-email']))
        ->call('save')
        ->assertHasNoErrors()
        ->set('tab', 'mail')
        ->call('save')
        ->assertHasErrors(['form.mail_from_address' => 'email']);
});

test('super-admin cannot be the role of new users', function () {
    $this->actingAs(User::factory()->withRole('super-admin')->create());

    Livewire::test('hata::admin.settings.edit')
        ->set('tab', 'access')
        ->set(siteSettingsInput(['form.default_role' => 'super-admin']))
        ->call('save')
        ->assertHasErrors(['form.default_role' => 'not_in']);
});

test('the role of new users must be one the actor may assign', function () {
    $this->actingAs(User::factory()->withPermissions(['admin.access', 'settings.edit'])->create());
    Role::create(['name' => 'manager', 'guard_name' => 'web'])->givePermissionTo('users.delete');

    Livewire::test('hata::admin.settings.edit')
        ->set('tab', 'access')
        ->set(siteSettingsInput(['form.default_role' => 'manager']))
        ->call('save')
        ->assertHasErrors(['form.default_role'])
        ->assertSee(__('hata::site_settings.messages.role_unavailable'));

    expect(Setting::count())->toBe(0);
});

test('it warns when the role of new users opens the admin panel', function () {
    $this->actingAs(User::factory()->withRole('admin')->create());

    Livewire::test('hata::admin.settings.edit')
        ->set('tab', 'access')
        ->set('form.default_role', 'editor')
        ->assertSee(__('hata::site_settings.access.admin_access_warning'))
        ->set('form.default_role', '')
        ->assertDontSee(__('hata::site_settings.access.admin_access_warning'));
});

test('it sends a test email to the current user', function () {
    $user = User::factory()->withRole('admin')->create();
    $this->actingAs($user);

    Livewire::test('hata::admin.settings.edit', ['tab' => 'mail'])
        ->call('sendTestEmail')
        ->assertDispatched('toast-show');

    $messages = app('mailer')->getSymfonyTransport()->messages();
    expect($messages)->toHaveCount(1)
        ->and($messages[0]->getEnvelope()->getRecipients()[0]->getAddress())->toBe($user->email);
});

test('it reports a failed test email', function () {
    $this->actingAs(User::factory()->withRole('admin')->create());
    app(SiteSettings::class)->put(['mail_mailer' => 'smtp', 'mail_host' => '127.0.0.1', 'mail_port' => 1, 'mail_scheme' => 'smtp']);

    Livewire::test('hata::admin.settings.edit', ['tab' => 'mail'])
        ->call('sendTestEmail')
        ->assertDispatched('toast-show', fn (string $name, array $params): bool => ($params['dataset']['variant'] ?? null) === 'danger');
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
        ->assertSee(__('hata::site_settings.unsaved.body', ['tab' => __('hata::site_settings.general.heading')]))
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

test('a successful save marks the form clean for the leave-page warning', function () {
    $this->actingAs(User::factory()->withRole('admin')->create());

    Livewire::test('hata::admin.settings.edit')
        ->set(siteSettingsInput(['form.site_name' => 'Renamed']))
        ->call('save')
        ->assertSet('savedValues.site_name', 'Renamed');
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

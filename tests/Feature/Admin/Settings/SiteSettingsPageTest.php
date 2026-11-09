<?php

use App\Models\Role;
use App\Models\Setting;
use App\Models\User;
use App\Support\Settings\SiteSettings;
use App\Support\Toast;
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
        ->assertSee(__('site_settings.mail.heading'));
});

test('the sidebar links to the page only with the settings.edit permission', function () {
    $this->actingAs(User::factory()->withPermissions(['admin.access', 'users.view'])->create());
    $this->get(route('admin.users.index'))->assertDontSee(route('admin.settings.edit'));

    $this->actingAs(User::factory()->withPermissions(['admin.access', 'settings.edit'])->create());
    $this->get(route('admin.dashboard'))->assertSee(route('admin.settings.edit'));
});

test('it saves the settings, applies them and redirects with a message', function () {
    $this->actingAs(User::factory()->withRole('admin')->create());

    Livewire::test('pages::admin.settings.edit')
        ->set(siteSettingsInput(['form.default_role' => 'author']))
        ->call('save')
        ->assertHasNoErrors()
        ->assertRedirect(route('admin.settings.edit'));

    expect(Setting::query()->pluck('value', 'key')->all())->toMatchArray([
        'site_name' => 'Hata',
        'default_locale' => 'uk',
        'timezone' => 'Europe/Kyiv',
        'registration_enabled' => true,
        'default_role' => 'author',
        'mail_from_address' => 'site@example.com',
        'mail_from_name' => 'Hata site',
    ])
        ->and(config('app.name'))->toBe('Hata')
        ->and(config('admin.registration_enabled'))->toBeTrue()
        ->and(session(Toast::SESSION_KEY)['text'])->toBe(__('site_settings.messages.saved'));
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

    Livewire::test('pages::admin.settings.edit')
        ->set(siteSettingsInput([...$smtp, 'form.mail_password' => 'secret']))
        ->call('save')
        ->assertHasNoErrors();

    Livewire::test('pages::admin.settings.edit')
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

    Livewire::test('pages::admin.settings.edit')
        ->assertSet('form.mail_mailer', 'smtp')
        ->set(siteSettingsInput())
        ->call('save')
        ->assertHasNoErrors();

    expect(Setting::query()->whereIn('key', ['mail_mailer', 'mail_host', 'mail_password'])->exists())->toBeFalse()
        ->and(config('mail.default'))->toBe('array');
});

test('it requires the SMTP host, port and encryption only for the SMTP mailer', function () {
    $this->actingAs(User::factory()->withRole('admin')->create());

    Livewire::test('pages::admin.settings.edit')
        ->set(siteSettingsInput(['form.mail_mailer' => 'smtp', 'form.mail_host' => '', 'form.mail_port' => '', 'form.mail_scheme' => '']))
        ->call('save')
        ->assertHasErrors(['form.mail_host' => 'required_if', 'form.mail_port' => 'required_if', 'form.mail_scheme' => 'required_if']);
});

test('it validates the general settings', function () {
    $this->actingAs(User::factory()->withRole('admin')->create());

    Livewire::test('pages::admin.settings.edit')
        ->set(siteSettingsInput([
            'form.site_name' => '',
            'form.default_locale' => 'fr',
            'form.timezone' => 'Mars/Olympus',
            'form.mail_from_address' => 'not-an-email',
        ]))
        ->call('save')
        ->assertHasErrors(['form.site_name', 'form.default_locale', 'form.timezone', 'form.mail_from_address']);

    expect(Setting::count())->toBe(0);
});

test('super-admin cannot be the role of new users', function () {
    $this->actingAs(User::factory()->withRole('super-admin')->create());

    Livewire::test('pages::admin.settings.edit')
        ->set(siteSettingsInput(['form.default_role' => 'super-admin']))
        ->call('save')
        ->assertHasErrors(['form.default_role' => 'not_in']);
});

test('the role of new users must be one the actor may assign', function () {
    $this->actingAs(User::factory()->withPermissions(['admin.access', 'settings.edit'])->create());
    Role::create(['name' => 'manager', 'guard_name' => 'web'])->givePermissionTo('users.delete');

    Livewire::test('pages::admin.settings.edit')
        ->set(siteSettingsInput(['form.default_role' => 'manager']))
        ->call('save')
        ->assertHasErrors(['form.default_role'])
        ->assertSee(__('site_settings.messages.role_unavailable'));

    expect(Setting::count())->toBe(0);
});

test('it warns when the role of new users opens the admin panel', function () {
    $this->actingAs(User::factory()->withRole('admin')->create());

    Livewire::test('pages::admin.settings.edit')
        ->set('form.default_role', 'editor')
        ->assertSee(__('site_settings.access.admin_access_warning'))
        ->set('form.default_role', '')
        ->assertDontSee(__('site_settings.access.admin_access_warning'));
});

test('it sends a test email to the current user', function () {
    $user = User::factory()->withRole('admin')->create();
    $this->actingAs($user);

    Livewire::test('pages::admin.settings.edit')
        ->call('sendTestEmail')
        ->assertDispatched('toast-show');

    $messages = app('mailer')->getSymfonyTransport()->messages();
    expect($messages)->toHaveCount(1)
        ->and($messages[0]->getEnvelope()->getRecipients()[0]->getAddress())->toBe($user->email);
});

test('it reports a failed test email', function () {
    $this->actingAs(User::factory()->withRole('admin')->create());
    app(SiteSettings::class)->put(['mail_mailer' => 'smtp', 'mail_host' => '127.0.0.1', 'mail_port' => 1, 'mail_scheme' => 'smtp']);

    Livewire::test('pages::admin.settings.edit')
        ->call('sendTestEmail')
        ->assertDispatched('toast-show', fn (string $name, array $params): bool => ($params['dataset']['variant'] ?? null) === 'danger');
});

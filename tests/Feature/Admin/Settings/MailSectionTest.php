<?php

use App\Models\User;
use Hata\Models\Setting;
use Hata\Support\Settings\SiteSettings;
use Hata\Support\Toast;
use Illuminate\Support\Facades\Crypt;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;

beforeEach(fn () => seedRolesAndPermissions());

/**
 * @return array<string, string>
 */
function smtpValues(array $overrides = []): array
{
    return [
        'values.mail_mailer' => 'smtp',
        'values.mail_host' => 'smtp.example.com',
        'values.mail_port' => '587',
        'values.mail_scheme' => 'smtp',
        'values.mail_username' => 'mailer',
        ...$overrides,
    ];
}

it('saves only the sender and redirects back to its tab with a message', function () {
    $this->actingAs(User::factory()->withRole('admin')->create());

    Livewire::test('hata::admin.settings.sections.mail')
        ->set('values.mail_from_address', 'site@example.com')
        ->set('values.mail_from_name', 'Hata site')
        ->call('save')
        ->assertHasNoErrors()
        ->assertRedirect(route('admin.settings.edit', ['tab' => 'mail']));

    expect(Setting::query()->pluck('value', 'key')->sortKeys()->all())->toBe(['mail_from_address' => 'site@example.com', 'mail_from_name' => 'Hata site'])
        ->and(session(Toast::SESSION_KEY)['text'])->toBe(__('hata::site_settings.messages.saved'));
});

it('stores the SMTP password encrypted and keeps it when the field is left empty', function () {
    $this->actingAs(User::factory()->withRole('admin')->create());

    Livewire::test('hata::admin.settings.sections.mail')
        ->set(smtpValues(['values.mail_password' => 'secret']))
        ->call('save')
        ->assertHasNoErrors();

    Livewire::test('hata::admin.settings.sections.mail')
        ->assertSet('values.mail_mailer', 'smtp')
        ->assertSet('values.mail_password', '')
        ->set('values.mail_host', 'smtp2.example.com')
        ->call('save')
        ->assertHasNoErrors();

    $stored = Setting::query()->where('key', 'mail_password')->value('value');
    expect($stored)->not->toBe('secret')
        ->and(Crypt::decryptString($stored))->toBe('secret')
        ->and(config('mail.default'))->toBe('smtp')
        ->and(config('mail.mailers.smtp.host'))->toBe('smtp2.example.com')
        ->and(config('mail.mailers.smtp.password'))->toBe('secret');
});

it('keeps the SMTP password when another SMTP field changes and the password is blank', function () {
    $this->actingAs(User::factory()->withRole('admin')->create());
    app(SiteSettings::class)->put(['mail_mailer' => 'smtp', 'mail_host' => 'smtp.example.com', 'mail_port' => 587, 'mail_scheme' => 'smtp', 'mail_password' => 'secret']);

    Livewire::test('hata::admin.settings.sections.mail')
        ->set('values.mail_username', 'someone')
        ->call('save')
        ->assertHasNoErrors();

    expect(config('mail.mailers.smtp.password'))->toBe('secret')
        ->and(config('mail.mailers.smtp.username'))->toBe('someone')
        ->and(config('mail.default'))->toBe('smtp');
});

it('switching to the SMTP mailer stores every SMTP field', function () {
    $this->actingAs(User::factory()->withRole('admin')->create());

    Livewire::test('hata::admin.settings.sections.mail')
        ->set(smtpValues())
        ->call('save')
        ->assertHasNoErrors();

    expect(Setting::query()->pluck('value', 'key')->only(['mail_mailer', 'mail_host', 'mail_port', 'mail_scheme', 'mail_username'])->all())
        ->toBe(['mail_mailer' => 'smtp', 'mail_host' => 'smtp.example.com', 'mail_port' => 587, 'mail_scheme' => 'smtp', 'mail_username' => 'mailer']);
});

it('switching back to the server mailer removes the SMTP settings', function () {
    $this->actingAs(User::factory()->withRole('admin')->create());
    app(SiteSettings::class)->put(['mail_mailer' => 'smtp', 'mail_host' => 'smtp.example.com', 'mail_password' => 'secret']);

    Livewire::test('hata::admin.settings.sections.mail')
        ->assertSet('values.mail_mailer', 'smtp')
        ->set('values.mail_mailer', 'server')
        ->call('save')
        ->assertHasNoErrors();

    expect(Setting::query()->whereIn('key', ['mail_mailer', 'mail_host', 'mail_password'])->exists())->toBeFalse()
        ->and(config('mail.default'))->toBe('array');
});

it('requires the SMTP host, port and encryption only for the SMTP mailer', function () {
    $this->actingAs(User::factory()->withRole('admin')->create());

    Livewire::test('hata::admin.settings.sections.mail')
        ->set(smtpValues(['values.mail_host' => '', 'values.mail_port' => '', 'values.mail_scheme' => '']))
        ->call('save')
        ->assertHasErrors(['values.mail_host' => 'required_if', 'values.mail_port' => 'required_if', 'values.mail_scheme' => 'required_if']);
});

it('validates the sender address', function () {
    $this->actingAs(User::factory()->withRole('admin')->create());

    Livewire::test('hata::admin.settings.sections.mail')
        ->set('values.mail_from_address', 'not-an-email')
        ->call('save')
        ->assertHasErrors(['values.mail_from_address' => 'email']);

    expect(Setting::count())->toBe(0);
});

it('a typed SMTP password counts as a change', function () {
    $this->actingAs(User::factory()->withRole('admin')->create());
    app(SiteSettings::class)->put(['mail_mailer' => 'smtp', 'mail_host' => 'smtp.example.com', 'mail_port' => 587, 'mail_scheme' => 'smtp']);

    Livewire::test('hata::admin.settings.sections.mail')
        ->set('values.mail_password', 'secret')
        ->call('save')
        ->assertHasNoErrors()
        ->assertSet('values.mail_password', '');

    expect(Crypt::decryptString(Setting::query()->where('key', 'mail_password')->value('value')))->toBe('secret');
});

it('sends a test email to the current user', function () {
    $user = User::factory()->withRole('admin')->create();
    $this->actingAs($user);

    Livewire::test('hata::admin.settings.sections.mail')
        ->call('sendTestEmail')
        ->assertDispatched('toast-show');

    $messages = app('mailer')->getSymfonyTransport()->messages();
    expect($messages)->toHaveCount(1)
        ->and($messages[0]->getEnvelope()->getRecipients()[0]->getAddress())->toBe($user->email);
});

it('reports a failed test email', function () {
    $this->actingAs(User::factory()->withRole('admin')->create());
    app(SiteSettings::class)->put(['mail_mailer' => 'smtp', 'mail_host' => '127.0.0.1', 'mail_port' => 1, 'mail_scheme' => 'smtp']);

    Livewire::test('hata::admin.settings.sections.mail')
        ->call('sendTestEmail')
        ->assertDispatched('toast-show', fn (string $name, array $params): bool => ($params['dataset']['variant'] ?? null) === 'danger');
});

it('does not send a test email without the settings.edit permission', function () {
    $user = User::factory()->withRole('admin')->create();
    $this->actingAs($user);

    $page = Livewire::test('hata::admin.settings.sections.mail');

    $user->syncRoles([]);
    $user->syncPermissions(['admin.access']);
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $page->call('sendTestEmail')->assertForbidden();

    expect(app('mailer')->getSymfonyTransport()->messages())->toHaveCount(0);
});

it('does not mount without the settings.edit permission', function () {
    $this->actingAs(User::factory()->withPermissions(['admin.access'])->create());

    Livewire::test('hata::admin.settings.sections.mail')->assertForbidden();
});

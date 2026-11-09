<?php

use App\Actions\Settings\UploadLogo;
use App\Models\Setting;
use App\Models\User;
use App\Support\Settings\SiteLogo;
use App\Support\Settings\SiteSettings;
use App\Support\Toast;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function () {
    seedRolesAndPermissions();
    Storage::fake(config('admin.logo.disk'));
});

test('uploading a logo stores it and reloads the logo tab with a message', function () {
    $this->actingAs(User::factory()->withRole('admin')->create());

    Livewire::test('pages::admin.settings.edit', ['tab' => 'logo'])
        ->set('logoLight', UploadedFile::fake()->image('logo.png', 200, 64))
        ->assertHasNoErrors()
        ->assertRedirect(route('admin.settings.edit', ['tab' => 'logo']));

    $path = Setting::query()->where('key', 'logo_light')->value('value');
    Storage::disk(config('admin.logo.disk'))->assertExists($path);
    expect(config('admin.logo.light'))->toBe($path)
        ->and(session(Toast::SESSION_KEY)['text'])->toBe(__('site_settings.logo.uploaded'));
});

test('an SVG logo is accepted', function () {
    $this->actingAs(User::factory()->withRole('admin')->create());

    Livewire::test('pages::admin.settings.edit', ['tab' => 'logo'])
        ->set('logoDark', UploadedFile::fake()->createWithContent('logo.svg', '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 10 10"><rect width="10" height="10"/></svg>'))
        ->assertHasNoErrors();

    expect(config('admin.logo.dark'))->toEndWith('.svg');
});

test('a new upload deletes the logo it replaces', function () {
    $this->actingAs(User::factory()->withRole('admin')->create());

    Livewire::test('pages::admin.settings.edit', ['tab' => 'logo'])
        ->set('logoLight', UploadedFile::fake()->image('first.png'));
    $first = config('admin.logo.light');

    Livewire::test('pages::admin.settings.edit', ['tab' => 'logo'])
        ->set('logoLight', UploadedFile::fake()->image('second.png'));

    Storage::disk(config('admin.logo.disk'))->assertMissing($first);
    Storage::disk(config('admin.logo.disk'))->assertExists(config('admin.logo.light'));
});

test('it rejects files that are not images or are too large', function (UploadedFile $file) {
    $this->actingAs(User::factory()->withRole('admin')->create());

    Livewire::test('pages::admin.settings.edit', ['tab' => 'logo'])
        ->set('logoLight', $file)
        ->assertHasErrors('logoLight');

    expect(Setting::count())->toBe(0);
})->with([
    'pdf' => fn () => UploadedFile::fake()->create('logo.pdf', 10, 'application/pdf'),
    'over 1 MB' => fn () => UploadedFile::fake()->image('logo.png')->size(1100),
]);

test('removing a logo deletes the file and brings back the default', function () {
    $this->actingAs(User::factory()->withRole('admin')->create());
    Livewire::test('pages::admin.settings.edit', ['tab' => 'logo'])
        ->set('logoLight', UploadedFile::fake()->image('logo.png'));
    $path = config('admin.logo.light');

    Livewire::test('pages::admin.settings.edit', ['tab' => 'logo'])
        ->call('removeLogo', 'light')
        ->assertRedirect(route('admin.settings.edit', ['tab' => 'logo']));

    Storage::disk(config('admin.logo.disk'))->assertMissing($path);
    expect(SiteLogo::isCustom())->toBeFalse()
        ->and(Setting::count())->toBe(0);
});

test('uploading a logo requires the settings.edit permission', function () {
    $actor = User::factory()->withPermissions(['admin.access', 'users.view'])->create();

    app(UploadLogo::class)->handle($actor, 'light', UploadedFile::fake()->image('logo.png'));
})->throws(AuthorizationException::class);

test('the logo route serves the uploaded file and falls back to the other theme', function () {
    Storage::disk(config('admin.logo.disk'))->put('branding/light.svg', '<svg xmlns="http://www.w3.org/2000/svg"/>');
    app(SiteSettings::class)->put(['logo_light' => 'branding/light.svg']);

    $this->get(SiteLogo::url('dark'))
        ->assertOk()
        ->assertHeader('Content-Security-Policy', "default-src 'none'; style-src 'unsafe-inline'; sandbox")
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertStreamedContent('<svg xmlns="http://www.w3.org/2000/svg"/>');
});

test('the logo route answers 404 without an uploaded logo', function () {
    $this->get(route('branding.logo', ['variant' => 'light']))->assertNotFound();
    $this->get('/branding/logo/other')->assertNotFound();
});

test('the layouts show the uploaded logo instead of the default mark', function () {
    $this->actingAs(User::factory()->withRole('admin')->create());
    $this->get(route('admin.dashboard'))->assertDontSee(route('branding.logo', ['variant' => 'light']), false);

    Storage::disk(config('admin.logo.disk'))->put('branding/light.png', 'png');
    app(SiteSettings::class)->put(['logo_light' => 'branding/light.png']);

    $this->get(route('admin.dashboard'))->assertSee(SiteLogo::url('light'), false);
    auth()->logout();
    $this->get(route('login'))->assertSee(SiteLogo::url('dark'), false);
});

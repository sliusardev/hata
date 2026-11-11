<?php

use App\Actions\Media\DeleteFolder;
use App\Actions\Media\DeleteMedia;
use App\Actions\Settings\RemoveLogo;
use App\Actions\Settings\SetLogo;
use App\Models\Media;
use App\Models\MediaFolder;
use App\Models\MediaUsage;
use App\Models\Setting;
use App\Models\User;
use App\Support\Settings\SiteLogo;
use App\Support\Settings\SiteSettings;
use App\Support\Toast;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

beforeEach(function () {
    seedRolesAndPermissions();
    Storage::fake('local');
});

function logoMedia(string $name = 'logo.webp', ?string $mime = null): Media
{
    $media = Media::factory()->image()->create(['name' => $name, ...($mime === null ? [] : ['mime' => $mime])]);
    Storage::disk($media->disk)->put($media->path, 'file');

    return $media;
}

function logoAdmin(): User
{
    return User::factory()->withRole('admin')->create();
}

test('choosing a logo through the picker binding stores the id, records the usage and reloads the tab', function () {
    $this->actingAs(logoAdmin());
    $media = logoMedia();

    // The picker writes the chosen id into the bound property (null is what the parent holds without a logo).
    Livewire::test('pages::admin.settings.edit', ['tab' => 'logo'])
        ->assertSet('logoLight', null)
        ->set('logoLight', $media->id)
        ->assertHasNoErrors()
        ->assertRedirect(route('admin.settings.edit', ['tab' => 'logo']));

    $setting = Setting::query()->where('key', 'logo_light')->firstOrFail();
    expect($setting->value)->toBe($media->id)
        ->and(config('admin.logo.light'))->toBe($media->id)
        ->and(MediaUsage::query()->where('media_id', $media->id)->where('field', 'logo_light')->where('usable_id', $setting->id)->count())->toBe(1)
        ->and(session(Toast::SESSION_KEY)['text'])->toBe(__('site_settings.logo.chosen'));
});

test('the page hands the picker the current logo and accepts the picker clearing it', function () {
    $this->actingAs(logoAdmin());
    $media = logoMedia();
    app(SetLogo::class)->handle(auth()->user(), 'dark', $media->id);

    Livewire::test('pages::admin.settings.edit', ['tab' => 'logo'])
        ->assertSet('logoDark', $media->id)
        ->assertSet('logoLight', null)
        ->assertSeeHtml('data-test="logo-preview-dark"')
        ->set('logoDark', null)
        ->assertRedirect(route('admin.settings.edit', ['tab' => 'logo']));

    expect(Setting::count())->toBe(0)
        ->and(MediaUsage::count())->toBe(0)
        ->and(Media::find($media->id))->not->toBeNull();
});

test('a GIF, a document, a missing id or a tampered value is refused with a field error and nothing is stored', function (string $case) {
    $this->actingAs(logoAdmin());
    $id = match ($case) {
        'array' => [1, 2],
        'gif' => logoMedia('spinner.gif', 'image/gif')->id,
        'document' => Media::factory()->document()->create()->id,
        'missing' => 987654,
    };

    Livewire::test('pages::admin.settings.edit', ['tab' => 'logo'])
        ->set('logoLight', $id)
        ->assertHasErrors('logoLight')
        ->assertSet('logoLight', null)
        ->assertNoRedirect();

    expect(Setting::count())->toBe(0)->and(MediaUsage::count())->toBe(0);
})->with(['gif', 'document', 'missing', 'array']);

test('a refused id puts the stored logo back in the field', function () {
    $this->actingAs(logoAdmin());
    $media = logoMedia();
    app(SetLogo::class)->handle(auth()->user(), 'light', $media->id);

    Livewire::test('pages::admin.settings.edit', ['tab' => 'logo'])
        ->set('logoLight', logoMedia('spinner.gif', 'image/gif')->id)
        ->assertHasErrors('logoLight')
        ->assertSet('logoLight', $media->id);

    expect(config('admin.logo.light'))->toBe($media->id);
});

test('opening the picker on an empty slot (the picker echoing the empty value) changes nothing', function (mixed $empty) {
    $this->actingAs(logoAdmin());

    Livewire::test('pages::admin.settings.edit', ['tab' => 'logo'])
        ->set('logoLight', $empty)
        ->assertNoRedirect()
        ->assertHasNoErrors();

    expect(session(Toast::SESSION_KEY))->toBeNull();
})->with([[''], [null]]);

test('choosing the logo that is already stored changes nothing', function () {
    $this->actingAs(logoAdmin());
    $media = logoMedia();
    app(SetLogo::class)->handle(auth()->user(), 'light', $media->id);

    Livewire::test('pages::admin.settings.edit', ['tab' => 'logo'])
        ->set('logoLight', (string) $media->id)
        ->assertNoRedirect();

    expect(session(Toast::SESSION_KEY))->toBeNull();
});

test('settings.edit without media.view cannot take a library file as a logo, but may keep the one in place', function () {
    $media = logoMedia();
    $other = logoMedia('other.png', 'image/png');
    app(SetLogo::class)->handle(logoAdmin(), 'light', $media->id);
    $actor = User::factory()->withPermissions(['admin.access', 'settings.edit'])->create();
    $this->actingAs($actor);

    Livewire::test('pages::admin.settings.edit', ['tab' => 'logo'])
        ->set('logoDark', $other->id)
        ->assertHasErrors('logoDark');

    expect(fn () => app(SetLogo::class)->handle($actor, 'dark', $other->id))->toThrow(AuthorizationException::class)
        ->and(Setting::query()->where('key', 'logo_dark')->exists())->toBeFalse();

    app(SetLogo::class)->handle($actor, 'light', $media->id);
    expect(config('admin.logo.light'))->toBe($media->id);
});

test('removing a logo unlinks it, keeps the file and drops the usage', function () {
    $this->actingAs(logoAdmin());
    $media = logoMedia();
    app(SetLogo::class)->handle(auth()->user(), 'light', $media->id);

    Livewire::test('pages::admin.settings.edit', ['tab' => 'logo'])
        ->call('removeLogo', 'light')
        ->assertRedirect(route('admin.settings.edit', ['tab' => 'logo']));

    expect(SiteLogo::isCustom())->toBeFalse()
        ->and(Setting::count())->toBe(0)
        ->and(MediaUsage::count())->toBe(0)
        ->and(Media::find($media->id))->not->toBeNull();
    Storage::disk('local')->assertExists($media->path);
});

test('a logo whose file is gone shows a note with Remove in its slot, and Remove clears it', function () {
    $this->actingAs(logoAdmin());
    $media = logoMedia();
    app(SetLogo::class)->handle(auth()->user(), 'dark', $media->id);
    Storage::disk($media->disk)->delete($media->path);
    SiteLogo::flush();

    $page = Livewire::test('pages::admin.settings.edit', ['tab' => 'logo'])
        ->assertSee(__('site_settings.logo.missing'))
        ->assertSeeHtml('data-test="logo-missing-dark"')
        ->assertDontSeeHtml('data-test="logo-missing-light"');

    $page->call('removeLogo', 'dark')
        ->assertRedirect(route('admin.settings.edit', ['tab' => 'logo']));

    expect(Setting::query()->where('key', 'logo_dark')->exists())->toBeFalse()
        ->and(MediaUsage::count())->toBe(0);
});

test('setting and removing a logo require settings.edit', function () {
    $actor = User::factory()->withPermissions(['admin.access', 'users.view'])->create();
    $media = logoMedia();

    expect(fn () => app(SetLogo::class)->handle($actor, 'light', $media->id))->toThrow(AuthorizationException::class)
        ->and(fn () => app(RemoveLogo::class)->handle($actor, 'light'))->toThrow(AuthorizationException::class);
});

test('SetLogo validates the file and replaces the usage of the previous one', function () {
    $actor = logoAdmin();
    $first = logoMedia('first.png', 'image/png');
    $second = logoMedia('second.svg', 'image/svg+xml');

    app(SetLogo::class)->handle($actor, 'light', $first->id);
    app(SetLogo::class)->handle($actor, 'light', $second->id);

    expect(MediaUsage::query()->pluck('media_id')->all())->toBe([$second->id])
        ->and(Setting::query()->where('key', 'logo_light')->count())->toBe(1);

    expect(fn () => app(SetLogo::class)->handle($actor, 'light', logoMedia('a.gif', 'image/gif')->id))->toThrow(ValidationException::class);
});

test('the layouts show the chosen logo instead of the default mark', function () {
    $this->actingAs(logoAdmin());
    $this->get(route('admin.dashboard'))->assertDontSee('<img', false);

    $media = logoMedia();
    app(SetLogo::class)->handle(auth()->user(), 'light', $media->id);

    $this->get(route('admin.dashboard'))->assertSee($media->url(), false);
    auth()->logout();
    $this->get(route('login'))->assertSee($media->url(), false);
});

test('every auth layout shows the chosen logo', function (string $layout) {
    $media = logoMedia();
    app(SetLogo::class)->handle(logoAdmin(), 'light', $media->id);

    expect(view($layout, ['slot' => ''])->render())->toContain(e($media->url()));
})->with(['layouts.auth.simple', 'layouts.auth.card', 'layouts.auth.split']);

test('deleting the logo file in the library clears the setting', function () {
    $actor = logoAdmin();
    $media = logoMedia();
    app(SetLogo::class)->handle($actor, 'light', $media->id);

    app(DeleteMedia::class)->handle($actor, $media);

    expect(Setting::count())->toBe(0)
        ->and(config('admin.logo.light'))->toBeNull()
        ->and(SiteLogo::isCustom())->toBeFalse()
        ->and(app(SiteSettings::class)->has('logo_light'))->toBeFalse();
});

test('deleting a folder that holds the logo file clears the setting', function () {
    $actor = logoAdmin();
    $folder = MediaFolder::factory()->create();
    $media = logoMedia();
    $media->update(['folder_id' => $folder->id]);
    app(SetLogo::class)->handle($actor, 'dark', $media->id);

    app(DeleteFolder::class)->handle($actor, $folder);

    expect(Setting::count())->toBe(0)->and(config('admin.logo.dark'))->toBeNull();
});

test('a delete that fails after the logo was forgotten leaves the setting, the cache and the files intact', function () {
    $actor = logoAdmin();
    $media = logoMedia();
    app(SetLogo::class)->handle($actor, 'light', $media->id);

    // The media rows are deleted after forgetMedia() ran inside the same transaction.
    DB::listen(function (QueryExecuted $query): void {
        if (preg_match('/^delete from [`"]media[`"]/', $query->sql) === 1) {
            throw new RuntimeException('boom');
        }
    });

    expect(fn () => app(DeleteMedia::class)->handle($actor, $media))->toThrow(RuntimeException::class);

    expect(Setting::query()->where('key', 'logo_light')->value('value'))->toBe($media->id)
        ->and(config('admin.logo.light'))->toBe($media->id)
        ->and(app(SiteSettings::class)->stored()['logo_light'])->toBe($media->id)
        ->and(MediaUsage::count())->toBe(1)
        ->and(Media::find($media->id))->not->toBeNull();
    Storage::disk('local')->assertExists($media->path);
});

test('the library details panel names the logo usage and links it only to users who may edit the settings', function () {
    $media = logoMedia();
    app(SetLogo::class)->handle(logoAdmin(), 'dark', $media->id);
    $url = route('admin.settings.edit', ['tab' => 'logo']);

    $this->actingAs(User::factory()->withRole('admin')->create());
    Livewire::test('pages::admin.media.index')->call('select', $media->id)
        ->assertSee(__('site_settings.logo.usage_dark'))
        ->assertSeeHtml('href="'.$url.'"');

    $this->actingAs(User::factory()->withPermissions(['admin.access', 'media.view'])->create());
    Livewire::test('pages::admin.media.index')->call('select', $media->id)
        ->assertSee(__('site_settings.logo.usage_dark'))
        ->assertDontSeeHtml('href="'.$url.'"');
});

test('without media.view the logo slots show the picker hint and no way to browse', function () {
    $this->actingAs(User::factory()->withPermissions(['admin.access', 'settings.edit'])->create());

    Livewire::test('pages::admin.settings.edit', ['tab' => 'logo'])
        ->assertSeeHtml('data-test="media-picker-needs-access"')
        ->assertDontSeeHtml('data-test="media-picker-open"');
});

test('the old logo route and upload class are gone', function () {
    $this->get('/branding/logo/light')->assertNotFound();
    expect(file_exists(app_path('Actions/Settings/UploadLogo.php')))->toBeFalse()
        ->and(Route::has('branding.logo'))->toBeFalse();
});

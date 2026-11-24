<?php

use App\Models\User;
use Hata\Actions\Media\DeleteFolder;
use Hata\Actions\Media\DeleteMedia;
use Hata\Actions\Settings\RemoveLogo;
use Hata\Actions\Settings\SetLogo;
use Hata\Hata;
use Hata\Models\Media;
use Hata\Models\MediaFolder;
use Hata\Models\MediaUsage;
use Hata\Models\Setting;
use Hata\Support\Settings\SiteLogo;
use Hata\Support\Settings\SiteSettings;
use Hata\Support\Toast;
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

function appearanceMedia(string $name = 'logo.webp', ?string $mime = null): Media
{
    $media = Media::factory()->image()->create(['name' => $name, ...($mime === null ? [] : ['mime' => $mime])]);
    Storage::disk($media->disk)->put($media->path, 'file');

    return $media;
}

function appearanceAdmin(): User
{
    return User::factory()->withRole('admin')->create();
}

const APPEARANCE = 'hata::admin.settings.sections.appearance';

test('choosing a logo through the picker binding stores nothing until Save, then stores the id, records the usage and reloads the tab', function () {
    $this->actingAs(appearanceAdmin());
    $media = appearanceMedia();

    $page = Livewire::test(APPEARANCE)
        ->assertSet('values.logo_light', null)
        ->set('values.logo_light', $media->id)
        ->assertHasNoErrors()
        ->assertNoRedirect();

    expect(Setting::count())->toBe(0)->and(MediaUsage::count())->toBe(0);

    $page->call('save')->assertHasNoErrors()->assertRedirect(route('admin.settings.edit', ['tab' => 'appearance']));

    $setting = Setting::query()->where('key', 'logo_light')->firstOrFail();
    expect($setting->value)->toBe($media->id)
        ->and(config('hata.admin.logo.light'))->toBe($media->id)
        ->and(MediaUsage::query()->where('media_id', $media->id)->where('field', 'logo_light')->where('usable_id', $setting->id)->count())->toBe(1)
        ->and(session(Toast::SESSION_KEY)['text'])->toBe(__('hata::site_settings.messages.saved'));
});

test('picking a logo makes the section dirty', function () {
    $this->actingAs(appearanceAdmin());
    $media = appearanceMedia();

    $page = Livewire::test(APPEARANCE)->set('values.logo_light', $media->id);

    expect($page->get('values')['logo_light'])->not->toBe($page->get('saved')['logo_light'])
        ->and($page->get('saved')['logo_light'])->toBeNull();
});

test('the section hands the picker the current logo and Save accepts the picker clearing it', function () {
    $this->actingAs(appearanceAdmin());
    $media = appearanceMedia();
    app(SetLogo::class)->handle(auth()->user(), 'dark', $media->id);

    Livewire::test(APPEARANCE)
        ->assertSet('values.logo_dark', $media->id)
        ->assertSet('values.logo_light', null)
        ->assertSeeHtml('data-test="logo-preview-dark"')
        ->set('values.logo_dark', null)
        ->call('save')
        ->assertRedirect(route('admin.settings.edit', ['tab' => 'appearance']));

    expect(Setting::count())->toBe(0)
        ->and(MediaUsage::count())->toBe(0)
        ->and(Media::find($media->id))->not->toBeNull();
});

test('a GIF, a document, a missing id or a tampered value is refused with a field error and nothing is stored', function (string $case) {
    $this->actingAs(appearanceAdmin());
    $id = match ($case) {
        'array' => [1, 2],
        'gif' => appearanceMedia('spinner.gif', 'image/gif')->id,
        'document' => Media::factory()->document()->create()->id,
        'missing' => 987654,
    };

    Livewire::test(APPEARANCE)
        ->set('values.logo_light', $id)
        ->call('save')
        ->assertHasErrors('values.logo_light')
        ->assertNoRedirect();

    expect(Setting::count())->toBe(0)->and(MediaUsage::count())->toBe(0);
})->with(['gif', 'document', 'missing', 'array']);

test('a refused id leaves the stored logo in place', function () {
    $this->actingAs(appearanceAdmin());
    $media = appearanceMedia();
    app(SetLogo::class)->handle(auth()->user(), 'light', $media->id);

    Livewire::test(APPEARANCE)
        ->set('values.logo_light', appearanceMedia('spinner.gif', 'image/gif')->id)
        ->call('save')
        ->assertHasErrors('values.logo_light');

    expect(config('hata.admin.logo.light'))->toBe($media->id);
});

test('opening the picker on an empty slot (the picker echoing the empty value) changes nothing', function (mixed $empty) {
    $this->actingAs(appearanceAdmin());

    $page = Livewire::test(APPEARANCE)->set('values.logo_light', $empty);

    expect($page->get('values')['logo_light'] ?? null)->toBeIn([null, '']);
    $page->call('save')->assertHasNoErrors();

    expect(Setting::count())->toBe(0);
})->with([[''], [null]]);

test('choosing the logo that is already stored changes nothing', function () {
    $this->actingAs(appearanceAdmin());
    $media = appearanceMedia();
    app(SetLogo::class)->handle(auth()->user(), 'light', $media->id);
    $before = Setting::query()->where('key', 'logo_light')->value('updated_at');

    Livewire::test(APPEARANCE)
        ->set('values.logo_light', (string) $media->id)
        ->call('save')
        ->assertHasNoErrors();

    expect(MediaUsage::count())->toBe(1)
        ->and(Setting::query()->where('key', 'logo_light')->value('updated_at'))->toEqual($before);
});

test('settings.edit without media.view cannot take a library file as a logo, but may keep the one in place', function () {
    $media = appearanceMedia();
    $other = appearanceMedia('other.png', 'image/png');
    app(SetLogo::class)->handle(appearanceAdmin(), 'light', $media->id);
    $actor = User::factory()->withPermissions(['admin.access', 'settings.edit'])->create();
    $this->actingAs($actor);

    Livewire::test(APPEARANCE)
        ->set('values.logo_dark', $other->id)
        ->call('save')
        ->assertHasErrors('values.logo_dark');

    expect(fn () => app(SetLogo::class)->handle($actor, 'dark', $other->id))->toThrow(AuthorizationException::class)
        ->and(Setting::query()->where('key', 'logo_dark')->exists())->toBeFalse();

    app(SetLogo::class)->handle($actor, 'light', $media->id);
    expect(config('hata.admin.logo.light'))->toBe($media->id);
});

test('removing a logo unlinks it, keeps the file and drops the usage', function () {
    $this->actingAs(appearanceAdmin());
    $media = appearanceMedia();
    app(SetLogo::class)->handle(auth()->user(), 'light', $media->id);

    Livewire::test(APPEARANCE)
        ->call('removeLogo', 'light')
        ->assertRedirect(route('admin.settings.edit', ['tab' => 'appearance']));

    expect(SiteLogo::isCustom())->toBeFalse()
        ->and(Setting::count())->toBe(0)
        ->and(MediaUsage::count())->toBe(0)
        ->and(Media::find($media->id))->not->toBeNull();
    Storage::disk('local')->assertExists($media->path);
});

test('a logo whose file is gone shows a note with Remove in its slot, and Remove clears it', function () {
    $this->actingAs(appearanceAdmin());
    $media = appearanceMedia();
    app(SetLogo::class)->handle(auth()->user(), 'dark', $media->id);
    Storage::disk($media->disk)->delete($media->path);
    SiteLogo::flush();

    $page = Livewire::test(APPEARANCE)
        ->assertSee(__('hata::site_settings.logo.missing'))
        ->assertSeeHtml('data-test="logo-missing-dark"')
        ->assertDontSeeHtml('data-test="logo-missing-light"');

    $page->call('removeLogo', 'dark')
        ->assertRedirect(route('admin.settings.edit', ['tab' => 'appearance']));

    expect(Setting::query()->where('key', 'logo_dark')->exists())->toBeFalse()
        ->and(MediaUsage::count())->toBe(0);
});

test('without media.view the logo slots show the picker hint and no way to browse', function () {
    $this->actingAs(User::factory()->withPermissions(['admin.access', 'settings.edit'])->create());

    Livewire::test(APPEARANCE)
        ->assertSeeHtml('data-test="media-picker-needs-access"')
        ->assertDontSeeHtml('data-test="media-picker-open"');
});

test('removeLogo needs the section permission', function () {
    $this->actingAs(User::factory()->withPermissions(['admin.access', 'users.view'])->create());

    Livewire::test(APPEARANCE)->assertForbidden();
});

test('it saves a custom color scheme and every page applies it', function () {
    $this->actingAs(User::factory()->withRole('admin')->create());

    Livewire::test(APPEARANCE)
        ->set('values.color_scheme', 'custom')
        ->set('values.color_accent', '#8A1C7C')
        ->set('values.color_gray', 'stone')
        ->set('values.color_tint_sidebar', false)
        ->call('save')
        ->assertHasNoErrors();

    expect(Setting::query()->pluck('value', 'key')->only(['color_scheme', 'color_accent', 'color_gray', 'color_tint_sidebar'])->all())
        ->toBe(['color_scheme' => 'custom', 'color_accent' => '#8a1c7c', 'color_gray' => 'stone', 'color_tint_sidebar' => false]);

    $this->get(route('admin.dashboard'))->assertSee('<style id="color-scheme">:root{', false)->assertSee('--color-accent:#8a1c7c', false);
});

test('a custom scheme needs a valid hex color', function (string $color) {
    $this->actingAs(User::factory()->withRole('admin')->create());

    Livewire::test(APPEARANCE)
        ->set('values.color_scheme', 'custom')
        ->set('values.color_accent', $color)
        ->call('save')
        ->assertHasErrors('values.color_accent');

    expect(Setting::count())->toBe(0);
})->with(['', 'blue', '#12345', '#12345g']);

test('the section previews the chosen scheme before it is saved', function () {
    $this->actingAs(User::factory()->withRole('admin')->create());

    Livewire::test(APPEARANCE)
        ->set('values.color_scheme', 'rose')
        ->assertSee('--color-accent:#e11d48', false);

    expect(Setting::count())->toBe(0);
});

test('appearance saves logos and colors all or nothing', function () {
    $this->actingAs(appearanceAdmin());
    $media = appearanceMedia();

    Livewire::test(APPEARANCE)
        ->set('values.logo_light', $media->id)
        ->set('values.color_scheme', 'custom')
        ->set('values.color_accent', 'nope')
        ->call('save')
        ->assertHasErrors('values.color_accent');

    expect(Setting::count())->toBe(0)->and(MediaUsage::count())->toBe(0);

    // A valid logo is written first, then a refused one throws: the transaction takes the first and the colors back.
    $light = appearanceMedia('light.png', 'image/png');

    Livewire::test(APPEARANCE)
        ->set('values.logo_light', $light->id)
        ->set('values.logo_dark', appearanceMedia('spinner.gif', 'image/gif')->id)
        ->set('values.color_scheme', 'rose')
        ->call('save')
        ->assertHasErrors('values.logo_dark');

    expect(Setting::count())->toBe(0)
        ->and(MediaUsage::count())->toBe(0)
        ->and(config('hata.admin.logo.light'))->toBeNull()
        ->and(config('hata.admin.colors.scheme'))->not->toBe('rose');
});

test('saving logo and colors together stores both', function () {
    $this->actingAs(appearanceAdmin());
    $media = appearanceMedia();

    Livewire::test(APPEARANCE)
        ->set('values.logo_dark', $media->id)
        ->set('values.color_scheme', 'rose')
        ->call('save')
        ->assertHasNoErrors();

    expect(Setting::query()->where('key', 'logo_dark')->value('value'))->toBe($media->id)
        ->and(Setting::query()->where('key', 'color_scheme')->value('value'))->toBe('rose')
        ->and(MediaUsage::count())->toBe(1);
});

test('setting and removing a logo require settings.edit', function () {
    $actor = User::factory()->withPermissions(['admin.access', 'users.view'])->create();
    $media = appearanceMedia();

    expect(fn () => app(SetLogo::class)->handle($actor, 'light', $media->id))->toThrow(AuthorizationException::class)
        ->and(fn () => app(RemoveLogo::class)->handle($actor, 'light'))->toThrow(AuthorizationException::class);
});

test('SetLogo validates the file and replaces the usage of the previous one', function () {
    $actor = appearanceAdmin();
    $first = appearanceMedia('first.png', 'image/png');
    $second = appearanceMedia('second.svg', 'image/svg+xml');

    app(SetLogo::class)->handle($actor, 'light', $first->id);
    app(SetLogo::class)->handle($actor, 'light', $second->id);

    expect(MediaUsage::query()->pluck('media_id')->all())->toBe([$second->id])
        ->and(Setting::query()->where('key', 'logo_light')->count())->toBe(1);

    expect(fn () => app(SetLogo::class)->handle($actor, 'light', appearanceMedia('a.gif', 'image/gif')->id))->toThrow(ValidationException::class);
});

test('the layouts show the chosen logo instead of the default mark', function () {
    $this->actingAs(appearanceAdmin());
    $this->get(route('admin.dashboard'))->assertDontSee('<img', false);

    $media = appearanceMedia();
    app(SetLogo::class)->handle(auth()->user(), 'light', $media->id);

    $this->get(route('admin.dashboard'))->assertSee($media->url(), false);
    auth()->logout();
    $this->get(route('login'))->assertSee($media->url(), false);
});

test('every auth layout shows the chosen logo', function (string $layout) {
    $media = appearanceMedia();
    app(SetLogo::class)->handle(appearanceAdmin(), 'light', $media->id);

    expect(view($layout, ['slot' => ''])->render())->toContain(e($media->url()));
})->with(['hata::layouts.auth.simple', 'hata::layouts.auth.card', 'hata::layouts.auth.split']);

test('deleting the logo file in the library clears the setting', function () {
    $actor = appearanceAdmin();
    $media = appearanceMedia();
    app(SetLogo::class)->handle($actor, 'light', $media->id);

    app(DeleteMedia::class)->handle($actor, $media);

    expect(Setting::count())->toBe(0)
        ->and(config('hata.admin.logo.light'))->toBeNull()
        ->and(SiteLogo::isCustom())->toBeFalse()
        ->and(app(SiteSettings::class)->has('logo_light'))->toBeFalse();
});

test('deleting a folder that holds the logo file clears the setting', function () {
    $actor = appearanceAdmin();
    $folder = MediaFolder::factory()->create();
    $media = appearanceMedia();
    $media->update(['folder_id' => $folder->id]);
    app(SetLogo::class)->handle($actor, 'dark', $media->id);

    app(DeleteFolder::class)->handle($actor, $folder);

    expect(Setting::count())->toBe(0)->and(config('hata.admin.logo.dark'))->toBeNull();
});

test('a delete that fails after the logo was forgotten leaves the setting, the cache and the files intact', function () {
    $actor = appearanceAdmin();
    $media = appearanceMedia();
    app(SetLogo::class)->handle($actor, 'light', $media->id);

    // The media rows are deleted after forgetMedia() ran inside the same transaction.
    DB::listen(function (QueryExecuted $query): void {
        if (preg_match('/^delete from [`"]media[`"]/', $query->sql) === 1) {
            throw new RuntimeException('boom');
        }
    });

    expect(fn () => app(DeleteMedia::class)->handle($actor, $media))->toThrow(RuntimeException::class);

    expect(Setting::query()->where('key', 'logo_light')->value('value'))->toBe($media->id)
        ->and(config('hata.admin.logo.light'))->toBe($media->id)
        ->and(app(SiteSettings::class)->stored()['logo_light'])->toBe($media->id)
        ->and(MediaUsage::count())->toBe(1)
        ->and(Media::find($media->id))->not->toBeNull();
    Storage::disk('local')->assertExists($media->path);
});

test('both logo variants are looked up with one query per request', function () {
    $actor = appearanceAdmin();
    app(SetLogo::class)->handle($actor, 'light', appearanceMedia()->id);
    app(SetLogo::class)->handle($actor, 'dark', appearanceMedia('logo-dark.webp')->id);
    SiteLogo::flush();

    DB::flushQueryLog();
    DB::enableQueryLog();
    $urls = [SiteLogo::url('light'), SiteLogo::url('dark'), SiteLogo::isCustom()];
    $queries = collect(DB::getQueryLog())->filter(fn (array $query): bool => str_contains($query['query'], 'from "media"') || str_contains($query['query'], 'from `media`'));
    DB::disableQueryLog();

    expect($queries)->toHaveCount(1)
        ->and($urls[0])->not->toBeNull()
        ->and($urls[1])->not->toBe($urls[0]);
});

test('the library details panel names the logo usage and links it only to users who may edit the settings', function () {
    $media = appearanceMedia();
    app(SetLogo::class)->handle(appearanceAdmin(), 'dark', $media->id);
    $url = route('admin.settings.edit', ['tab' => 'appearance']);

    $this->actingAs(User::factory()->withRole('admin')->create());
    Livewire::test('hata::admin.media.index')->call('select', $media->id)
        ->assertSee(__('hata::site_settings.logo.usage_dark'))
        ->assertSeeHtml('href="'.$url.'"');

    $this->actingAs(User::factory()->withPermissions(['admin.access', 'media.view'])->create());
    Livewire::test('hata::admin.media.index')->call('select', $media->id)
        ->assertSee(__('hata::site_settings.logo.usage_dark'))
        ->assertDontSeeHtml('href="'.$url.'"');
});

test('the old logo route and upload class are gone', function () {
    $this->get('/branding/logo/light')->assertNotFound();
    expect(file_exists(Hata::path('src/Actions/Settings/UploadLogo.php')))->toBeFalse()
        ->and(Route::has('branding.logo'))->toBeFalse();
});

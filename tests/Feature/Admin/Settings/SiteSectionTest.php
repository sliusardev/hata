<?php

use App\Models\User;
use Hata\Models\Entry;
use Hata\Models\Media;
use Hata\Models\MediaUsage;
use Hata\Models\Setting;
use Hata\Support\Settings\SiteSettings;
use Hata\Support\Toast;
use Illuminate\Support\Facades\Storage;
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

const SITE = 'hata::admin.settings.sections.site';

function siteAdmin(): User
{
    return User::factory()->withRole('admin')->create();
}

function siteShareImage(int $width = 1200, int $height = 630): Media
{
    $media = Media::factory()->image($width, $height)->create(['name' => "share-{$width}.png", 'mime' => 'image/png']);
    Storage::disk($media->disk)->put($media->path, 'file');

    return $media;
}

test('saving a chosen page stores the home page', function () {
    $this->actingAs(siteAdmin());
    $page = Entry::factory()->published()->create(['title' => 'Welcome']);

    Livewire::test(SITE)->set('values.home_page', $page->id)->call('save')->assertHasNoErrors();

    expect(Setting::query()->where('key', 'home_page')->value('value'))->toBe($page->id)
        ->and(config('hata.content.home_page'))->toBe($page->id);
});

test('a trashed page or a post is refused as the home page', function (string $case) {
    $this->actingAs(siteAdmin());
    $id = $case === 'trashed'
        ? Entry::factory()->create(['deleted_at' => now()])->id
        : Entry::factory()->create(['type' => 'posts'])->id;

    Livewire::test(SITE)->set('values.home_page', $id)->call('save')->assertHasErrors('values.home_page');

    expect(Setting::count())->toBe(0);
})->with(['trashed', 'post']);

test('choosing the built-in home page stores nothing for it again', function () {
    $this->actingAs(siteAdmin());
    $page = Entry::factory()->published()->create();
    app(SiteSettings::class)->put(['home_page' => $page->id]);

    Livewire::test(SITE)
        ->assertSet('values.home_page', $page->id)
        ->set('values.home_page', null)
        ->call('save')
        ->assertHasNoErrors();

    expect(Setting::query()->where('key', 'home_page')->exists())->toBeFalse()
        ->and(config('hata.content.home_page'))->toBeNull();
});

test('the candidates are live pages of the default language found by their title', function () {
    $this->actingAs(siteAdmin());
    $about = Entry::factory()->published()->inLocale('uk')->create(['title' => 'Про нас']);
    config(['hata.content.default_locale' => 'uk', 'hata.content.locales' => [['code' => 'uk', 'active' => true], ['code' => 'en', 'active' => true]]]);
    $english = Entry::factory()->published()->create(['title' => 'Про English']);
    $post = Entry::factory()->inLocale('uk')->create(['type' => 'posts', 'title' => 'Про пост']);
    $trashed = Entry::factory()->inLocale('uk')->create(['title' => 'Про видалену', 'deleted_at' => now()]);

    $ids = Livewire::test(SITE)->set('homeSearch', 'про')->instance()->homeCandidates()->pluck('id')->all();

    expect($ids)->toBe([$about->id])
        ->and($ids)->not->toContain($english->id, $post->id, $trashed->id);
});

test('typing in the search does not make the form dirty', function () {
    $this->actingAs(siteAdmin());

    $page = Livewire::test(SITE)->set('homeSearch', 'abc');

    expect($page->get('values'))->toBe($page->get('saved'));
    $page->call('save');
    expect(Setting::count())->toBe(0);
});

test('descriptions are stored by language, blanks removed and off languages kept', function () {
    $this->actingAs(siteAdmin());
    config(['hata.content.locales' => [['code' => 'en', 'active' => true], ['code' => 'uk', 'active' => true], ['code' => 'fr', 'active' => false]]]);
    app(SiteSettings::class)->put(['site_description' => ['fr' => 'Bonjour', 'uk' => 'Старий']]);

    Livewire::test(SITE)
        ->assertSet('values.site_description', ['en' => '', 'uk' => 'Старий'])
        ->set('values.site_description.uk', 'Опис')
        ->call('save')
        ->assertHasNoErrors();

    expect(json_decode(Setting::query()->where('key', 'site_description')->firstOrFail()->value, true))->toBe(['fr' => 'Bonjour', 'uk' => 'Опис']);

    Livewire::test(SITE)->set('values.site_description.uk', '')->set('values.site_description.en', 'Hello')->call('save');
    expect(json_decode(Setting::query()->where('key', 'site_description')->firstOrFail()->value, true))->toBe(['fr' => 'Bonjour', 'en' => 'Hello']);
});

test('a description over 300 characters is an error', function () {
    $this->actingAs(siteAdmin());

    Livewire::test(SITE)
        ->set('values.site_description.en', str_repeat('a', 301))
        ->call('save')
        ->assertHasErrors('values.site_description.en');

    expect(Setting::count())->toBe(0);
});

test('saving only the site name stores nothing for the new fields', function () {
    $this->actingAs(siteAdmin());

    Livewire::test(SITE)->set('values.site_name', 'Renamed')->call('save')->assertHasNoErrors();

    expect(Setting::query()->pluck('key')->all())->toBe(['site_name']);
});

test('the share image is stored with its usage and warns when it is small', function () {
    $this->actingAs(siteAdmin());
    $big = siteShareImage();
    $small = siteShareImage(500, 300);

    $page = Livewire::test(SITE)
        ->set('values.og_image', $small->id)
        ->assertSeeHtml('data-test="share-image-small"')
        ->set('values.og_image', $big->id)
        ->assertDontSeeHtml('data-test="share-image-small"')
        ->call('save')
        ->assertHasNoErrors();

    expect(config('hata.seo.image'))->toBe($big->id)
        ->and(MediaUsage::query()->where('field', 'og_image')->count())->toBe(1);

    Livewire::test(SITE)->assertSet('values.og_image', $big->id)->set('values.og_image', null)->call('save');
    expect(Setting::query()->where('key', 'og_image')->exists())->toBeFalse();
});

test('a failing share image rolls back a changed site name', function () {
    $this->actingAs(siteAdmin());
    $svg = Media::factory()->image()->create(['name' => 'a.svg', 'mime' => 'image/svg+xml']);

    Livewire::test(SITE)
        ->set('values.site_name', 'Renamed')
        ->set('values.og_image', $svg->id)
        ->call('save')
        ->assertHasErrors('values.og_image');

    expect(Setting::count())->toBe(0)->and(config('app.name'))->not->toBe('Renamed');
});

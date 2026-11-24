<?php

use App\Models\User;
use Hata\Actions\Content\DeleteEntry;
use Hata\Actions\Content\PruneEntryTrash;
use Hata\Actions\Content\UnlinkTranslation;
use Hata\Models\Entry;
use Hata\Models\Setting;
use Hata\Support\Localization\ContentLocales;
use Hata\Support\Settings\SiteSettings;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    seedRolesAndPermissions();

    app(SiteSettings::class)->put([
        'content_locales' => [['code' => 'uk', 'active' => true], ['code' => 'en', 'active' => true]],
        'content_default_locale' => 'uk',
    ]);
});

function chooseHome(Entry $entry): void
{
    config(['hata.content.home_page' => $entry->id]);
}

function homeUrl(string $locale = 'uk'): string
{
    return app(ContentLocales::class)->route('home', [], $locale);
}

/**
 * @return array{0: Entry, 1: Entry} the uk page and its published en translation
 */
function homePages(): array
{
    $uk = Entry::factory()->page()->published()->inLocale('uk')->create(['title' => 'Про нас', 'slug' => 'about', 'path' => 'about', 'body' => '<p>Ми робимо дахи.</p>']);
    $en = Entry::factory()->page()->published()->translationOf($uk, 'en')->create(['title' => 'About us', 'slug' => 'about-us', 'path' => 'about-us', 'body' => '<p>We build roofs.</p>']);

    return [$uk, $en];
}

/**
 * @param  list<string>  $tables
 */
function homeQueries(Closure $request, array $tables): int
{
    $count = 0;

    DB::listen(function (QueryExecuted $query) use (&$count, $tables): void {
        $count += array_any($tables, fn (string $table): bool => (bool) preg_match('/["`\s]'.$table.'["`\s]/', $query->sql)) ? 1 : 0;
    });

    $request();

    return $count;
}

function homeSettingsEditor(): User
{
    return User::factory()->withPermissions(['settings.edit'])->create();
}

describe('the neutral home', function () {
    it('has the site name as its title, the description of the language in the head and the home canonical', function () {
        app(SiteSettings::class)->put(['site_description' => ['uk' => 'Дахи під ключ']]);

        $this->get('/')->assertOk()
            ->assertSee('<title>'.config('app.name').'</title>', escape: false)
            ->assertSee('<meta name="description" content="Дахи під ключ">', escape: false)
            ->assertDontSeeText('Дахи під ключ')
            ->assertSee('<link rel="canonical" href="'.homeUrl().'">', escape: false)
            ->assertSee('<meta property="og:type" content="website">', escape: false)
            ->assertDontSee('data-test="home-hint"', escape: false);
    });

    it('introduces Hata in the language of the address, with the admin link', function () {
        $this->get('/')->assertOk()
            ->assertSee('<h1', escape: false)
            ->assertSeeText('Ваш сайт готовий.')
            ->assertSeeText('Відкрити адмінку')
            ->assertSee('href="'.route('admin.dashboard').'"', escape: false)
            ->assertDontSee('data-test="home-hint"', escape: false);

        $this->get('/en/')->assertOk()
            ->assertSeeText('Your site is ready.')
            ->assertSeeText('Open the admin')
            ->assertSee('href="'.route('admin.dashboard').'"', escape: false);

        $this->actingAs(homeSettingsEditor())->get('/en/')->assertOk()
            ->assertSeeText('Your site is ready.')
            ->assertSee('data-test="home-hint"', escape: false);
    });

    it('shows a user who may edit the settings the hint to choose a home page', function () {
        $this->actingAs(homeSettingsEditor())->get('/')->assertOk()
            ->assertSee('data-test="home-hint"', escape: false)
            ->assertSeeText(__('hata::content.public.home.choose', [], 'uk'))
            ->assertSee(route('admin.settings.edit', ['tab' => 'site']), escape: false);
    });

    it('is shown where the chosen page is not public, with the other hint for the editor and none for a guest', function () {
        [$uk, $en] = homePages();
        $en->update(['status' => 'draft']);
        chooseHome($uk);

        $this->get('/en/')->assertOk()
            ->assertSee('<h1', escape: false)
            ->assertDontSee('We build roofs')
            ->assertDontSee('data-test="home-hint"', escape: false);

        $this->actingAs(homeSettingsEditor())->get('/en/')->assertOk()
            ->assertSeeText(__('hata::content.public.home.unpublished', [], 'en'));
    });

    it('is shown everywhere when the chosen record and its translations are trashed', function () {
        [$uk] = homePages();
        chooseHome($uk);
        Entry::query()->where('entry_group_id', $uk->entry_group_id)->get()->each->delete();

        $this->get('/')->assertOk()->assertDontSee('Ми робимо дахи');
        $this->get('/en/')->assertOk()->assertDontSee('We build roofs');
    });

    it('is shown in the language of a trashed chosen record while its translations still show', function () {
        [$uk] = homePages();
        chooseHome($uk);
        $uk->delete();

        $this->get('/')->assertOk()->assertDontSee('Ми робимо дахи');
        $this->get('/en/')->assertOk()->assertSee('We build roofs');
    });

    it('is shown when the setting names a record of another type', function () {
        $other = Entry::factory()->published()->inLocale('uk')->create(['type' => 'posts', 'slug' => 'news', 'path' => 'news', 'body' => '<p>Новина</p>']);
        chooseHome($other);

        $this->get('/')->assertOk()->assertDontSee('Новина');
    });
});

describe('the chosen page', function () {
    it('is shown at the address of each language, with the home address as its canonical', function () {
        [$uk] = homePages();
        chooseHome($uk);

        $this->get('/')->assertOk()
            ->assertSeeText('Про нас')
            ->assertSee('Ми робимо дахи')
            ->assertSee('<link rel="canonical" href="'.homeUrl().'">', escape: false)
            ->assertSee('<meta property="og:type" content="website">', escape: false);

        $this->get('/en/')->assertOk()
            ->assertSeeText('About us')
            ->assertSee('We build roofs')
            ->assertSee('<link rel="canonical" href="'.homeUrl('en').'">', escape: false);
    });

    it('keeps the alternates at each language\'s home', function () {
        [$uk] = homePages();
        chooseHome($uk);

        $this->get('/')
            ->assertSee('hreflang="en" href="'.homeUrl('en').'"', escape: false)
            ->assertDontSee(url('/about-us'), escape: false);
    });

    it('stays the home of the group after the language\'s record is unlinked from it', function () {
        [$uk, $en] = homePages();
        chooseHome($uk);

        $admin = User::factory()->withRole('admin')->create();
        app(UnlinkTranslation::class)->handle($admin, $en);

        $this->get('/')->assertOk()->assertSee('Ми робимо дахи');
        $this->get('/en/')->assertOk()->assertDontSee('We build roofs')->assertSee('<h1', escape: false);
    });
});

it('reflects a changed home page and fresh head tags on the next request through the same router', function () {
    [$uk] = homePages();
    $other = Entry::factory()->page()->published()->inLocale('uk')->create(['title' => 'Контакти', 'slug' => 'contacts', 'path' => 'contacts', 'body' => '<p>Телефон</p>']);

    chooseHome($uk);
    $this->get('/')->assertOk()->assertSee('Ми робимо дахи');
    $this->get('/contacts')->assertOk()->assertSee('<link rel="canonical" href="'.url('/contacts').'">', escape: false);

    chooseHome($other);
    $this->get('/')->assertOk()
        ->assertSee('Телефон')
        ->assertDontSee('Ми робимо дахи')
        ->assertSee('<link rel="canonical" href="'.homeUrl().'">', escape: false);

    config(['hata.content.home_page' => null]);
    $this->get('/')->assertOk()->assertDontSee('Телефон');
});

describe('cost', function () {
    it('adds no query for the neutral home and one entries query for a chosen page', function () {
        $count = fn (string $path, array $tables): int => homeQueries(fn () => $this->get($path)->assertOk(), $tables);

        expect($count('/', ['entries', 'media']))->toBe(0);

        [$uk] = homePages();
        chooseHome($uk);

        expect($count('/', ['entries']))->toBe(1);
    });
});

describe('the chosen page\'s own address', function () {
    it('redirects to the language\'s home with no-cache, for the record and its translations', function () {
        [$uk, $en] = homePages();
        chooseHome($uk);

        $this->get('/about')->assertStatus(301)->assertRedirect(homeUrl())->assertHeader('Cache-Control', 'no-cache, private');
        $this->get('/en/about-us')->assertStatus(301)->assertRedirect(homeUrl('en'));
    });

    it('redirects a public translation when the chosen record is a draft', function () {
        [$uk] = homePages();
        $uk->update(['status' => 'draft']);
        chooseHome($uk);

        $this->get('/en/')->assertOk()->assertSee('We build roofs');
        $this->get('/en/about-us')->assertStatus(301)->assertRedirect(homeUrl('en'));
    });

    it('redirects a public translation when the chosen record is trashed', function () {
        [$uk] = homePages();
        chooseHome($uk);
        $uk->delete();

        $this->get('/en/')->assertOk()->assertSee('We build roofs');
        $this->get('/en/about-us')->assertStatus(301)->assertRedirect(homeUrl('en'));
    });

    it('redirects on a one-language site when the chosen record is in a language that is off', function () {
        app(SiteSettings::class)->put([
            'content_locales' => [['code' => 'uk', 'active' => true], ['code' => 'en', 'active' => false]],
            'content_default_locale' => 'uk',
        ]);
        $en = Entry::factory()->page()->published()->inLocale('en')->create(['title' => 'About us', 'slug' => 'about-us', 'path' => 'about-us', 'body' => '<p>We build roofs.</p>']);
        Entry::factory()->page()->published()->translationOf($en, 'uk')->create(['title' => 'Про нас', 'slug' => 'about', 'path' => 'about', 'body' => '<p>Ми робимо дахи.</p>']);
        chooseHome($en);

        $this->get('/')->assertOk()->assertSee('Ми робимо дахи');
        $this->get('/about')->assertStatus(301)->assertRedirect(homeUrl());
    });

    it('leaves the pages under it alone', function () {
        [$uk] = homePages();
        Entry::factory()->page()->published()->inLocale('uk')->create(['title' => 'Команда', 'slug' => 'team', 'path' => 'about/team', 'parent_id' => $uk->id, 'body' => '<p>Люди</p>']);
        chooseHome($uk);

        $this->get('/about/team')->assertOk()->assertSee('Люди');
    });

    it('does not redirect an editor who opens a draft translation, which is a preview', function () {
        [$uk, $en] = homePages();
        $en->update(['status' => 'draft']);
        chooseHome($uk);

        $this->actingAs(User::factory()->withRole('admin')->create())->withCookie((string) config('session.cookie'), 'a-session')->get('/en/about-us')
            ->assertOk()
            ->assertSee('noindex', escape: false);
    });

    it('costs no query more than the page did', function () {
        [$uk] = homePages();

        $queries = function (int $status): int {
            $count = 0;
            DB::listen(function () use (&$count): void {
                $count++;
            });
            $this->get('/about')->assertStatus($status);

            return $count;
        };

        $before = $queries(200);
        chooseHome($uk);
        $after = $queries(301);

        expect($after)->toBeLessThanOrEqual($before);
    });
});

describe('deleting the chosen page for good', function () {
    it('clears the setting and its row when DeleteEntry purges it', function () {
        [$uk] = homePages();
        app(SiteSettings::class)->put(['home_page' => $uk->id]);
        $uk->delete();

        app(DeleteEntry::class)->handle(User::factory()->withRole('admin')->create(), Entry::onlyTrashed()->findOrFail($uk->id));

        expect(config('hata.content.home_page'))->toBeNull()
            ->and(Setting::query()->where('key', 'home_page')->exists())->toBeFalse();
    });

    it('clears it when the trash is pruned', function () {
        [$uk] = homePages();
        app(SiteSettings::class)->put(['home_page' => $uk->id]);
        $uk->delete();
        Entry::onlyTrashed()->whereKey($uk->id)->update(['deleted_at' => now()->subDays(31)]);

        app(PruneEntryTrash::class)->handle();

        expect(config('hata.content.home_page'))->toBeNull()
            ->and(Setting::query()->where('key', 'home_page')->exists())->toBeFalse();
    });

    it('keeps it when another record is purged', function () {
        [$uk] = homePages();
        $other = Entry::factory()->page()->published()->inLocale('uk')->create(['slug' => 'other', 'path' => 'other']);
        app(SiteSettings::class)->put(['home_page' => $uk->id]);
        $other->delete();

        app(DeleteEntry::class)->handle(User::factory()->withRole('admin')->create(), Entry::onlyTrashed()->findOrFail($other->id));

        expect(config('hata.content.home_page'))->toBe($uk->id)
            ->and(Setting::query()->where('key', 'home_page')->exists())->toBeTrue();
    });
});

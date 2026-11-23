<?php

use App\Models\User;
use Hata\Actions\Settings\AddContentLocale;
use Hata\Actions\Settings\RemoveContentLocale;
use Hata\Actions\Settings\SetContentLocaleActive;
use Hata\Actions\Settings\SetDefaultContentLocale;
use Hata\Exceptions\ContentLocaleException;
use Hata\Models\Entry;
use Hata\Models\Setting;
use Hata\Support\Localization\ContentLocales;
use Hata\Support\Localization\Language;
use Hata\Support\Localization\LanguageCatalog;
use Hata\Support\Localization\ReservedSlugs;
use Hata\Support\Settings\SiteSettings;
use Hata\Support\Toast;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Route;
use Livewire\Component;
use Livewire\Livewire;

beforeEach(function () {
    seedRolesAndPermissions();
});

/**
 * Store the site's content languages: code => active, and the default.
 *
 * @param  array<string, bool>  $languages
 */
function siteLanguages(array $languages, string $default, array $more = []): void
{
    app(SiteSettings::class)->put([
        'content_locales' => array_map(fn (string $code, bool $active): array => ['code' => $code, 'active' => $active], array_keys($languages), $languages),
        'content_default_locale' => $default,
        ...$more,
    ]);
}

/**
 * Register public routes the way routes/web.php does, after the application has booted.
 */
function localizedRoutes(Closure $routes): void
{
    Route::localized($routes);
    Route::getRoutes()->refreshNameLookups();
    Route::getRoutes()->refreshActionLookups();
}

function languagesAdmin(): User
{
    return User::factory()->withPermissions(['admin.access', 'settings.edit'])->create();
}

class LocaleProbeComponent extends Component
{
    public int $count = 0;

    public function bump(): void
    {
        $this->count++;
    }

    public function render(): string
    {
        return <<<'BLADE'
            <div>probe:{{ app(Hata\Support\Localization\ContentLocales::class)->current()->code }}|{{ app()->getLocale() }}|{{ app(Hata\Support\Localization\ContentLocales::class)->route('probe.component', absolute: false) }}|{{ app('translator')->getFallback() }}|{{ $count }}</div>
            BLADE;
    }
}

describe('catalogue', function () {
    it('holds well-formed codes, the interface languages among them', function () {
        $catalog = app(LanguageCatalog::class);

        foreach ($catalog->codes() as $code) {
            expect($code)->toMatch('/^[a-z]{2,3}(-[a-z0-9]{2,8})?$/');
        }

        expect(array_diff(array_keys(config('app.available_locales')), $catalog->codes()))->toBe([])
            ->and($catalog->find('uk'))->toEqual(new Language('uk', 'Ukrainian', 'Українська'))
            ->and($catalog->find('xx'))->toBeNull();
    });

    it('writes the code the way hreflang wants it and knows the direction', function (string $code, string $hreflang, string $direction) {
        $language = app(LanguageCatalog::class)->find($code);

        expect($language->hreflang())->toBe($hreflang)->and($language->direction())->toBe($direction);
    })->with([
        ['uk', 'uk', 'ltr'],
        ['pt-br', 'pt-BR', 'ltr'],
        ['zh-hans', 'zh-Hans', 'ltr'],
        ['ar', 'ar', 'rtl'],
    ]);

    it('knows which languages the interface is translated into', function () {
        expect(app(LanguageCatalog::class)->find('uk')->hasInterface())->toBeTrue()
            ->and(app(LanguageCatalog::class)->find('de')->hasInterface())->toBeFalse();
    });
});

describe('the site\'s languages', function () {
    it('has one language, the default interface one, while nothing is stored', function () {
        $locales = app(ContentLocales::class);

        expect($locales->entries())->toBe([['code' => 'en', 'active' => true]])
            ->and($locales->defaultCode())->toBe('en')
            ->and($locales->current()->code)->toBe('en');

        app(SiteSettings::class)->put(['default_locale' => 'uk']);

        expect($locales->defaultCode())->toBe('uk');
    });

    it('does not take a request\'s own language for the site default', function () {
        app()->setLocale('uk');

        expect(app(ContentLocales::class)->interfaceDefault())->toBe('en')
            ->and(app(ContentLocales::class)->defaultCode())->toBe('en');

        app(SiteSettings::class)->put(['site_name' => 'Again']);

        expect(app(ContentLocales::class)->interfaceDefault())->toBe('en');
    });

    it('puts the default first and keeps it on', function () {
        siteLanguages(['de' => false, 'uk' => false, 'en' => true], 'uk');
        $locales = app(ContentLocales::class);

        expect($locales->entries())->toBe([
            ['code' => 'uk', 'active' => true],
            ['code' => 'de', 'active' => false],
            ['code' => 'en', 'active' => true],
        ])
            ->and(array_map(fn (Language $language): string => $language->code, $locales->active()))->toBe(['uk', 'en'])
            ->and($locales->isActive('de'))->toBeFalse()
            ->and($locales->has('de'))->toBeTrue()
            ->and($locales->has('pl'))->toBeFalse();
    });

    it('survives broken stored values', function (mixed $list, mixed $default, array $expected) {
        app(SiteSettings::class)->put(['content_locales' => $list, 'content_default_locale' => $default]);

        expect(app(ContentLocales::class)->entries())->toBe($expected);
    })->with([
        'unknown and repeated codes' => [[['code' => 'xx', 'active' => true], ['code' => 'uk', 'active' => true], ['code' => 'uk', 'active' => false], 'junk'], 'uk', [['code' => 'uk', 'active' => true]]],
        'a default that is not in the list' => [[['code' => 'uk', 'active' => true], ['code' => 'en', 'active' => false]], 'de', [['code' => 'en', 'active' => true], ['code' => 'uk', 'active' => true]]],
        'a default that is not a language' => [[['code' => 'uk', 'active' => false]], 'nope', [['code' => 'uk', 'active' => true]]],
        'an empty list' => [[], 'de', [['code' => 'de', 'active' => true]]],
        'not a list' => ['oops', 'nope', [['code' => 'en', 'active' => true]]],
    ]);
});

describe('actions', function () {
    it('needs settings.edit', function () {
        $user = User::factory()->withPermissions(['admin.access'])->create();

        expect(fn () => app(AddContentLocale::class)->handle($user, 'de'))->toThrow(AuthorizationException::class)
            ->and(fn () => app(SetContentLocaleActive::class)->handle($user, 'en', false))->toThrow(AuthorizationException::class)
            ->and(fn () => app(SetDefaultContentLocale::class)->handle($user, 'en'))->toThrow(AuthorizationException::class)
            ->and(fn () => app(RemoveContentLocale::class)->handle($user, 'en'))->toThrow(AuthorizationException::class)
            ->and(Setting::count())->toBe(0);
    });

    it('adds a catalogue language switched off, and stores the derived default with it', function () {
        app(AddContentLocale::class)->handle(languagesAdmin(), 'de');

        expect(app(ContentLocales::class)->entries())->toBe([['code' => 'en', 'active' => true], ['code' => 'de', 'active' => false]])
            ->and(app(SiteSettings::class)->value('content_default_locale'))->toBe('en');
    });

    it('refuses a language that is unknown or already added', function () {
        $actor = languagesAdmin();

        expect(fn () => app(AddContentLocale::class)->handle($actor, 'xx'))->toThrow(ContentLocaleException::class, __('site_settings.languages.errors.unknown'))
            ->and(fn () => app(AddContentLocale::class)->handle($actor, 'en'))->toThrow(ContentLocaleException::class, __('site_settings.languages.errors.already_added'));
    });

    it('switches a language on and off, but never the default', function () {
        siteLanguages(['en' => true, 'uk' => false], 'en');
        $actor = languagesAdmin();

        app(SetContentLocaleActive::class)->handle($actor, 'uk', true);
        expect(app(ContentLocales::class)->isActive('uk'))->toBeTrue();

        app(SetContentLocaleActive::class)->handle($actor, 'uk', false);
        expect(app(ContentLocales::class)->isActive('uk'))->toBeFalse()
            ->and(fn () => app(SetContentLocaleActive::class)->handle($actor, 'en', false))->toThrow(ContentLocaleException::class, __('site_settings.languages.errors.is_default'))
            ->and(fn () => app(SetContentLocaleActive::class)->handle($actor, 'de', true))->toThrow(ContentLocaleException::class, __('site_settings.languages.errors.not_added'));
    });

    it('makes an active language the default, and the interface default only when asked and possible', function () {
        siteLanguages(['en' => true, 'uk' => true, 'de' => true, 'pl' => false], 'en');
        $actor = languagesAdmin();
        $locales = app(ContentLocales::class);

        app(SetDefaultContentLocale::class)->handle($actor, 'uk');
        expect($locales->defaultCode())->toBe('uk')->and($locales->interfaceDefault())->toBe('en');

        app(SetDefaultContentLocale::class)->handle($actor, 'uk', alsoInterface: true);
        expect($locales->interfaceDefault())->toBe('uk');

        app(SetDefaultContentLocale::class)->handle($actor, 'de', alsoInterface: true);
        expect($locales->defaultCode())->toBe('de')
            ->and($locales->interfaceDefault())->toBe('uk')
            ->and($locales->isActive('uk'))->toBeTrue()
            ->and(fn () => app(SetDefaultContentLocale::class)->handle($actor, 'pl'))->toThrow(ContentLocaleException::class, __('site_settings.languages.errors.inactive'))
            ->and(fn () => app(SetDefaultContentLocale::class)->handle($actor, 'fr'))->toThrow(ContentLocaleException::class, __('site_settings.languages.errors.not_added'));
    });

    it('removes a language, but never the default', function () {
        siteLanguages(['en' => true, 'uk' => true], 'en');
        $actor = languagesAdmin();

        app(RemoveContentLocale::class)->handle($actor, 'uk');

        expect(app(ContentLocales::class)->entries())->toBe([['code' => 'en', 'active' => true]])
            ->and(fn () => app(RemoveContentLocale::class)->handle($actor, 'en'))->toThrow(ContentLocaleException::class, __('site_settings.languages.errors.is_default'))
            ->and(fn () => app(RemoveContentLocale::class)->handle($actor, 'uk'))->toThrow(ContentLocaleException::class, __('site_settings.languages.errors.not_added'));
    });

    it('refuses to remove a language that has records, those in the trash included, and says how many', function () {
        siteLanguages(['en' => true, 'uk' => true], 'en');
        $actor = languagesAdmin();
        Entry::factory()->page()->inLocale('uk')->create()->delete();

        expect(fn () => app(RemoveContentLocale::class)->handle($actor, 'uk'))
            ->toThrow(ContentLocaleException::class, trans_choice('site_settings.languages.errors.has_content', 1))
            ->and(app(ContentLocales::class)->has('uk'))->toBeTrue()
            ->and(trans_choice('site_settings.languages.errors.has_content', 1))->toContain('1');

        Entry::factory()->page()->inLocale('uk')->create();

        expect(fn () => app(RemoveContentLocale::class)->handle($actor, 'uk'))
            ->toThrow(ContentLocaleException::class, trans_choice('site_settings.languages.errors.has_content', 2));
    });
});

describe('addresses', function () {
    beforeEach(function () {
        siteLanguages(['en' => true, 'uk' => true, 'de' => false], 'en');
    });

    it('opens the default language without a prefix and an active one under its code', function () {
        $this->get('/')->assertOk()->assertSee('<html lang="en" dir="ltr">', escape: false);
        $this->get('/uk')->assertOk()->assertSee('<html lang="uk" dir="ltr">', escape: false);
    });

    it('answers 404 for a language that is off, not added, or not a language', function (string $path) {
        $this->get($path)->assertNotFound();
    })->with(['/de', '/pl', '/xx', '/UK']);

    it('redirects the default language\'s own prefix for good, without letting a browser keep it', function () {
        $response = $this->get('/en?from=mail&x=1');

        $response->assertStatus(301)->assertRedirect(url('/?from=mail&x=1'));
        expect($response->headers->get('Cache-Control'))->toContain('no-cache');
    });

    it('opens a language as soon as it is switched on', function () {
        app(SetContentLocaleActive::class)->handle(languagesAdmin(), 'de', true);

        $this->get('/de')->assertOk()->assertSee('<html lang="de"', escape: false);
    });

    it('follows a change of the default language', function () {
        app(SetDefaultContentLocale::class)->handle(languagesAdmin(), 'uk');

        $this->get('/')->assertOk()->assertSee('<html lang="uk"', escape: false);
        $this->get('/en')->assertOk()->assertSee('<html lang="en"', escape: false);
        $this->get('/uk')->assertStatus(301)->assertRedirect(url('/'));
    });

    it('keeps the language prefix away from the page and builds addresses in any language', function () {
        localizedRoutes(fn () => Route::get('probe/{id}', fn (string $id) => [
            'id' => $id,
            'parameters' => request()->route()->parameters(),
            'current' => app(ContentLocales::class)->current()->code,
            'here' => app(ContentLocales::class)->route('probe', ['id' => $id], absolute: false),
            'in_uk' => app(ContentLocales::class)->route('probe', ['id' => $id], 'uk', false),
            'in_en' => app(ContentLocales::class)->route('probe', ['id' => $id], 'en', false),
            'alternates' => app(ContentLocales::class)->alternates(),
        ])->name('probe'));

        $this->get('/uk/probe/7')->assertOk()->assertExactJson([
            'id' => '7',
            'parameters' => ['id' => '7'],
            'current' => 'uk',
            'here' => '/uk/probe/7',
            'in_uk' => '/uk/probe/7',
            'in_en' => '/probe/7',
            'alternates' => ['en' => url('/probe/7'), 'uk' => url('/uk/probe/7')],
        ]);

        $this->get('/probe/7')->assertOk()->assertJson(['id' => '7', 'current' => 'en', 'here' => '/probe/7']);
    });

    it('gives an address with a language prefix to that language, not to a wildcard page of the default one', function () {
        localizedRoutes(function () {
            Route::get('{slug}', fn (string $slug) => ['route' => 'page', 'slug' => $slug, 'current' => app(ContentLocales::class)->current()->code])->name('page');
            Route::get('{slug}/{child}', fn (string $slug, string $child) => ['route' => 'child', 'slug' => $slug, 'child' => $child, 'current' => app(ContentLocales::class)->current()->code])->name('child');
        });

        $this->get('/uk/about')->assertOk()->assertExactJson(['route' => 'page', 'slug' => 'about', 'current' => 'uk']);
        $this->get('/uk/about/team')->assertOk()->assertExactJson(['route' => 'child', 'slug' => 'about', 'child' => 'team', 'current' => 'uk']);
        $this->get('/about/team')->assertOk()->assertExactJson(['route' => 'child', 'slug' => 'about', 'child' => 'team', 'current' => 'en']);
        $this->get('/about')->assertOk()->assertExactJson(['route' => 'page', 'slug' => 'about', 'current' => 'en']);
        $this->get('/en/about')->assertStatus(301)->assertRedirect(url('/about'));
        $this->get('/de/about')->assertNotFound();
    });

    it('does not let a visitor skip the redirect with a Livewire header', function () {
        $this->withHeaders(['X-Livewire' => 'true'])->get('/en')->assertStatus(301);
    });

    it('prints the hreflang alternates of a public page, and none with one language', function () {
        $this->get('/uk')
            ->assertSee('<link rel="alternate" hreflang="en" href="'.url('/').'" />', escape: false)
            ->assertSee('<link rel="alternate" hreflang="uk" href="'.url('/uk').'" />', escape: false)
            ->assertSee('<link rel="alternate" hreflang="x-default" href="'.url('/').'" />', escape: false)
            ->assertSee('data-test="language-switcher"', escape: false)
            ->assertDontSee('hreflang="de"', escape: false);

        siteLanguages(['en' => true], 'en');

        $this->get('/')->assertDontSee('hreflang=', escape: false)->assertDontSee('data-test="language-switcher"', escape: false);
    });

    it('has no alternates outside public pages', function () {
        expect(app(ContentLocales::class)->alternates())->toBe([]);
    });

    it('keeps the routes independent of the settings, so they can be cached', function () {
        $route = Route::getRoutes()->getByName('localized.home');
        $pattern = $route->wheres['locale'];

        app(AddContentLocale::class)->handle(languagesAdmin(), 'pl');
        $route->prepareForSerialization();

        expect($pattern)->toBe(app(LanguageCatalog::class)->pattern())
            ->and(Route::getRoutes()->getByName('localized.home')->wheres['locale'])->toBe($pattern)
            ->and(Route::getRoutes()->getByName('home')->uri())->toBe('/');
    });

    it('leaves the service routes without a language prefix', function () {
        $this->get('/uk/login')->assertNotFound();
        $this->get('/login')->assertOk();
    });

    it('shows a string the content language lacks in the site\'s default interface language', function () {
        siteLanguages(['en' => true, 'uk' => true, 'de' => true], 'en', ['default_locale' => 'uk']);
        localizedRoutes(fn () => Route::get('probe-text', fn () => __('common.actions.save'))->name('probe.text'));

        $this->get('/de/probe-text')->assertOk()->assertSeeText('Зберегти');
        $this->get('/probe-text')->assertOk()->assertSeeText('Save');
    });
});

describe('Livewire on a public page', function () {
    it('keeps the page\'s content language on its update requests', function () {
        siteLanguages(['en' => true, 'uk' => true], 'en');
        Livewire::component('locale-probe', LocaleProbeComponent::class);
        localizedRoutes(fn () => Route::get('probe-component', fn () => Blade::render('<livewire:locale-probe />'))->name('probe.component'));

        $page = $this->get('/uk/probe-component')->assertOk()->assertSee('probe:uk|uk|/uk/probe-component|en|0');

        preg_match('/wire:snapshot="([^"]+)"/', $page->getContent(), $matches);
        $update = fn () => $this->withHeaders(['X-Livewire' => 'true'])->postJson(app('livewire')->getUpdateUri(), [
            'components' => [[
                'snapshot' => html_entity_decode($matches[1], ENT_QUOTES),
                'updates' => [],
                'calls' => [['path' => '', 'method' => 'bump', 'params' => []]],
            ]],
        ]);

        // One test process serves every request, so what a real request starts without is dropped by hand:
        // the language the page request left in the singleton, and Livewire's per-request state.
        $freshRequest = function (): void {
            app()->forgetInstance(ContentLocales::class);
            app('livewire')->flushState();
        };

        $freshRequest();
        $update()->assertOk()->assertSee('probe:uk|uk|\/uk\/probe-component|en|1', escape: false);

        app(SetContentLocaleActive::class)->handle(languagesAdmin(), 'uk', false);
        $freshRequest();

        $update()->assertNotFound();
    });
});

describe('service pages', function () {
    beforeEach(function () {
        siteLanguages(['en' => true, 'uk' => true, 'de' => true], 'en');
    });

    it('shows a guest the sign-in page in the language of the site they last saw', function () {
        $this->get(route('login'))->assertSee('<html lang="en"', escape: false);

        $this->get('/uk')->assertOk();
        $this->get(route('login'))->assertSee('<html lang="uk"', escape: false);

        $this->get('/')->assertOk();
        $this->get(route('login'))->assertSee('<html lang="en"', escape: false);
    });

    it('uses the default when the interface is not translated into that language', function () {
        $this->get('/de')->assertOk();

        $this->get(route('login'))->assertSee('<html lang="en"', escape: false);
    });

    it('uses the default once that language is switched off', function () {
        $this->get('/uk')->assertOk();
        app(SetContentLocaleActive::class)->handle(languagesAdmin(), 'uk', false);

        $this->get(route('login'))->assertSee('<html lang="en"', escape: false);
    });

    it('shows a signed-in user the site in the language of its address and the rest in their own', function () {
        $this->actingAs(User::factory()->create(['locale' => 'en']));

        $this->get('/uk')->assertOk()->assertSee('<html lang="uk"', escape: false);
        $this->get(route('profile.edit'))->assertSee('<html lang="en"', escape: false);

        $this->actingAs(User::factory()->create(['locale' => 'uk']));

        $this->get('/')->assertOk()->assertSee('<html lang="en"', escape: false);
        $this->get(route('profile.edit'))->assertSee('<html lang="uk"', escape: false);
    });
});

describe('reserved slugs', function () {
    it('reserves language codes, service routes and public files', function (string $slug) {
        expect(app(ReservedSlugs::class)->contains($slug))->toBeTrue();
    })->with(['en', 'de', 'pt-br', 'PL', 'admin', 'login', 'settings', 'media', 'storage', 'dashboard', 'up', 'robots.txt', '/Admin/']);

    it('leaves ordinary slugs and public routes free', function () {
        localizedRoutes(fn () => Route::get('blog/{slug}', fn () => 'post')->name('blog.show'));
        Route::get('reports/monthly', fn () => 'report');

        expect(app(ReservedSlugs::class)->contains('about-us'))->toBeFalse()
            ->and(app(ReservedSlugs::class)->contains('blog'))->toBeFalse()
            ->and(app(ReservedSlugs::class)->contains('reports'))->toBeTrue();
    });
});

describe('languages tab', function () {
    it('lists the site\'s languages and offers the rest of the catalogue', function () {
        siteLanguages(['en' => true, 'uk' => false], 'en');

        Livewire::withQueryParams(['tab' => 'languages'])->actingAs(languagesAdmin())->test('pages::admin.settings.edit')
            ->assertSet('tab', 'languages')
            ->assertSeeHtml('data-test="site-language-en"')
            ->assertSeeHtml('data-test="site-language-uk"')
            ->assertSee('Українська')
            ->assertSee(__('site_settings.languages.badges.default'))
            ->assertSeeHtml('data-test="add-language-de"')
            ->assertDontSeeHtml('data-test="add-language-uk"')
            ->assertDontSeeHtml('data-test="language-switch-en"')
            ->assertSeeHtml('data-test="language-switch-uk"');
    });

    it('does not load the languages on other tabs', function () {
        Livewire::actingAs(languagesAdmin())->test('pages::admin.settings.edit')
            ->assertDontSeeHtml('data-test="site-languages"')
            ->assertDontSeeHtml('data-test="language-catalog"');
    });

    it('adds a language and reloads the tab', function () {
        Livewire::withQueryParams(['tab' => 'languages'])->actingAs(languagesAdmin())->test('pages::admin.settings.edit')
            ->call('addLanguage', 'de')
            ->assertRedirect(route('admin.settings.edit', ['tab' => 'languages']));

        expect(app(ContentLocales::class)->has('de'))->toBeTrue()
            ->and(app(ContentLocales::class)->isActive('de'))->toBeFalse()
            ->and(session(Toast::SESSION_KEY)['text'])->toBe(__('site_settings.languages.messages.added', ['language' => 'German']));
    });

    it('refuses an unknown language with a message and stores nothing', function () {
        Livewire::withQueryParams(['tab' => 'languages'])->actingAs(languagesAdmin())->test('pages::admin.settings.edit')
            ->call('addLanguage', '../etc')
            ->assertNoRedirect();

        expect(Setting::count())->toBe(0);
    });

    it('turns a language on at once and off only after the confirmation', function () {
        siteLanguages(['en' => true, 'uk' => false], 'en');
        $page = fn () => Livewire::withQueryParams(['tab' => 'languages'])->actingAs(languagesAdmin())->test('pages::admin.settings.edit');

        $page()->call('toggleLanguage', 'uk')->assertRedirect(route('admin.settings.edit', ['tab' => 'languages']));
        expect(app(ContentLocales::class)->isActive('uk'))->toBeTrue();

        $confirming = $page()->call('toggleLanguage', 'uk')
            ->assertNoRedirect()
            ->assertSet('showLanguageModal', true)
            ->assertSee(__('site_settings.languages.confirm.off.heading', ['language' => 'Ukrainian']));
        expect(app(ContentLocales::class)->isActive('uk'))->toBeTrue();

        $confirming->call('runLanguageAction')->assertRedirect(route('admin.settings.edit', ['tab' => 'languages']));
        expect(app(ContentLocales::class)->isActive('uk'))->toBeFalse();
    });

    it('makes a language the default, with the interface following unless unticked', function (bool $alsoInterface, string $interface) {
        siteLanguages(['en' => true, 'uk' => true], 'en');

        Livewire::withQueryParams(['tab' => 'languages'])->actingAs(languagesAdmin())->test('pages::admin.settings.edit')
            ->call('confirmLanguage', 'default', 'uk')
            ->assertSet('languageAlsoInterface', true)
            ->assertSee(__('site_settings.languages.confirm.default.also_interface'))
            ->set('languageAlsoInterface', $alsoInterface)
            ->call('runLanguageAction')
            ->assertRedirect(route('admin.settings.edit', ['tab' => 'languages']));

        expect(app(ContentLocales::class)->defaultCode())->toBe('uk')
            ->and(app(ContentLocales::class)->interfaceDefault())->toBe($interface);
    })->with([[true, 'uk'], [false, 'en']]);

    it('offers no interface checkbox for a language the admin is not translated into', function () {
        siteLanguages(['en' => true, 'de' => true], 'en');

        Livewire::withQueryParams(['tab' => 'languages'])->actingAs(languagesAdmin())->test('pages::admin.settings.edit')
            ->call('confirmLanguage', 'default', 'de')
            ->assertSet('showLanguageModal', true)
            ->assertDontSee(__('site_settings.languages.confirm.default.also_interface'));
    });

    it('removes a language after the confirmation', function () {
        siteLanguages(['en' => true, 'uk' => true], 'en');

        Livewire::withQueryParams(['tab' => 'languages'])->actingAs(languagesAdmin())->test('pages::admin.settings.edit')
            ->call('confirmLanguage', 'remove', 'uk')
            ->assertSet('showLanguageModal', true)
            ->call('runLanguageAction')
            ->assertRedirect(route('admin.settings.edit', ['tab' => 'languages']));

        expect(app(ContentLocales::class)->has('uk'))->toBeFalse();
    });

    it('drops an unsaved choice of the interface language when the list changes, so the page can reload', function () {
        Livewire::withQueryParams(['tab' => 'languages'])->actingAs(languagesAdmin())->test('pages::admin.settings.edit')
            ->set('form.default_locale', 'uk')
            ->call('addLanguage', 'de')
            ->assertSet('form.default_locale', 'en')
            ->assertRedirect(route('admin.settings.edit', ['tab' => 'languages']));

        expect(app(ContentLocales::class)->interfaceDefault())->toBe('en');
    });

    it('ignores a confirmation for an unknown action or language', function () {
        Livewire::withQueryParams(['tab' => 'languages'])->actingAs(languagesAdmin())->test('pages::admin.settings.edit')
            ->call('confirmLanguage', 'destroy', 'en')
            ->assertSet('showLanguageModal', false)
            ->call('confirmLanguage', 'remove', 'pl')
            ->assertSet('showLanguageModal', false)
            ->call('runLanguageAction')
            ->assertNoRedirect();
    });

    it('shows the site\'s interface default in the field, not the administrator\'s own language', function () {
        $admin = User::factory()->withPermissions(['admin.access', 'settings.edit'])->create(['locale' => 'uk']);

        $this->actingAs($admin)->get(route('admin.settings.edit', ['tab' => 'languages']))
            ->assertOk()
            ->assertSee('<html lang="uk"', escape: false);

        app()->setLocale('uk');

        Livewire::withQueryParams(['tab' => 'languages'])->actingAs($admin)->test('pages::admin.settings.edit')
            ->assertSet('form.default_locale', 'en');
    });
});

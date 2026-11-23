<?php

use App\Models\User;
use Hata\Http\Middleware\LookUpContent;
use Hata\Http\Middleware\SetContentLocale;
use Hata\Models\Entry;
use Hata\Models\EntryRedirect;
use Hata\Models\Media;
use Hata\Support\Content\ContentRouter;
use Hata\Support\Content\ContentTypes;
use Hata\Support\Content\EntryPaths;
use Hata\Support\Localization\ContentLocales;
use Hata\Support\Localization\ReservedSlugs;
use Hata\Support\Settings\SiteSettings;
use Illuminate\Http\Request;
use Illuminate\Routing\CompiledRouteCollection;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\Fixtures\ProductsType;

beforeEach(function () {
    seedRolesAndPermissions();

    publicLanguages(['uk' => true, 'en' => true, 'de' => true, 'pl' => false], 'uk');
});

/**
 * Store the site's content languages: code => active, and the default.
 *
 * @param  array<string, bool>  $languages
 */
function publicLanguages(array $languages, string $default): void
{
    app(SiteSettings::class)->put([
        'content_locales' => array_map(fn (string $code, bool $active): array => ['code' => $code, 'active' => $active], array_keys($languages), $languages),
        'content_default_locale' => $default,
    ]);
}

/**
 * A published Ukrainian page `about` with a published English translation `about-us`.
 *
 * @return array{0: Entry, 1: Entry}
 */
function aboutPages(array $uk = [], array $en = []): array
{
    $about = Entry::factory()->page()->published()->inLocale('uk')->create(['title' => 'Про нас', 'slug' => 'about', 'path' => 'about', ...$uk]);
    $aboutUs = Entry::factory()->page()->published()->translationOf($about, 'en')->create(['title' => 'About us', 'slug' => 'about-us', 'path' => 'about-us', ...$en]);

    return [$about, $aboutUs];
}

/**
 * Signs the user in the way a browser is: with the session cookie, which a signed-in visitor always sends
 * (LookUpContent::mayBeSignedIn()); `actingAs()` alone sends no cookie.
 */
function browseAs(User $user): void
{
    test()->actingAs($user)->withCookie((string) config('session.cookie'), 'a-session');
}

/**
 * A Ukrainian draft `draft`, a scheduled page `scheduled` and an old path `old-draft` that leads to the draft.
 */
function hiddenContent(): void
{
    $draft = Entry::factory()->page()->draft()->inLocale('uk')->create(['slug' => 'draft', 'path' => 'draft']);
    Entry::factory()->page()->scheduled()->inLocale('uk')->create(['slug' => 'scheduled', 'path' => 'scheduled']);
    EntryRedirect::query()->create(['type' => 'pages', 'locale' => 'uk', 'path' => 'old-draft', 'entry_id' => $draft->id]);
}

function pageEditor(): User
{
    return User::factory()->withRole('editor')->create();
}

/**
 * The name of the route the router picks for an address.
 */
function routeNameFor(string $uri, string $method = 'GET'): ?string
{
    return Route::getRoutes()->match(Request::create($uri, $method))->getName();
}

/**
 * How many queries a request makes; with `$tables`, only those that read these tables.
 *
 * @param  list<string>  $tables
 */
function publicQueriesOf(Closure $request, array $tables = []): int
{
    DB::flushQueryLog();
    DB::enableQueryLog();
    $request();
    $queries = DB::getQueryLog();
    DB::disableQueryLog();

    if ($tables !== []) {
        $queries = array_filter($queries, fn (array $query): bool => array_any(
            $tables,
            fn (string $table): bool => (bool) preg_match('/["`\s]'.$table.'["`\s]/', $query['query']),
        ));
    }

    return count($queries);
}

describe('pages', function () {
    it('shows a published page in the default language and its translation under the language prefix', function () {
        aboutPages(['body' => '<p>Ми робимо дахи.</p>'], ['body' => '<p>We build roofs.</p>']);

        $this->get('/about')->assertOk()
            ->assertSee('<html lang="uk" dir="ltr">', escape: false)
            ->assertSee('<h1', escape: false)
            ->assertSeeText('Про нас')
            ->assertSee('<p>Ми робимо дахи.</p>', escape: false);

        $this->get('/en/about-us')->assertOk()
            ->assertSee('<html lang="en" dir="ltr">', escape: false)
            ->assertSeeText('About us')
            ->assertSee('<p>We build roofs.</p>', escape: false);

        $this->get('/en/about')->assertNotFound();
        $this->get('/about-us')->assertNotFound();
    });

    it('finds a nested page by its full path, in any language', function () {
        [$about, $aboutUs] = aboutPages();
        $team = Entry::factory()->published()->childOf($about)->create(['title' => 'Команда', 'slug' => 'team']);
        Entry::factory()->published()->childOf($aboutUs)->translationOf($team, 'en')->create(['title' => 'Our team', 'slug' => 'our-team', 'path' => 'about-us/our-team', 'parent_id' => $aboutUs->id]);

        $this->get('/about/team')->assertOk()->assertSeeText('Команда');
        $this->get('/en/about-us/our-team')->assertOk()->assertSeeText('Our team');
        $this->get('/team')->assertNotFound();
    });

    it('leaves a trailing slash to the framework\'s trim, as for every route', function () {
        aboutPages();

        $this->get('/about/')->assertOk();
    });

    it('opens a record under its type\'s base, and the list of the type at the bare base', function () {
        app(ContentTypes::class)->register(new ProductsType);
        Entry::factory()->published()->create(['type' => 'products', 'locale' => 'uk', 'title' => 'Чашка', 'slug' => 'mug', 'path' => 'mug']);

        $this->get('/products/mug')->assertOk()->assertSeeText('Чашка');
        $this->get('/products')->assertOk()->assertSeeText('Чашка')->assertSee('href="'.url('/products/mug').'"', escape: false);
        $this->get('/mug')->assertNotFound();
    });

    it('prints the image with its size and alt, and the title from the SEO title or the title', function () {
        $image = Media::factory()->image(1200, 800)->create(['alt' => ['uk' => 'Дах']]);
        [$about] = aboutPages(['image_id' => $image->id, 'seo_title' => 'Хто ми']);

        $this->get('/about')->assertOk()
            ->assertSee('<title>Хто ми</title>', escape: false)
            ->assertSee('src="'.e($image->url()).'"', escape: false)
            ->assertSee('width="1200"', escape: false)
            ->assertSee('height="800"', escape: false)
            ->assertSee('alt="Дах"', escape: false);

        $about->update(['seo_title' => null]);

        $this->get('/about')->assertSee('<title>Про нас - '.config('app.name').'</title>', escape: false);
    });

    it('renders the body with its images resolved', function () {
        $image = Media::factory()->image(640, 480)->create();
        aboutPages(['body' => '<p>Text</p><img data-media-id="'.$image->id.'" alt="Roof">']);

        $this->get('/about')->assertOk()
            ->assertSee('src="'.e($image->url()).'"', escape: false)
            ->assertSee('alt="Roof"', escape: false)
            ->assertDontSee('data-media-id', escape: false);
    });

    it('is a plain page without Livewire, Flux or the admin\'s scripts', function () {
        aboutPages(['body' => '<p>Text</p>']);
        // The real Vite with a manifest of its own, so the page's stylesheets can be told apart.
        $this->withVite();
        $public = sys_get_temp_dir().'/hata-public-'.Str::random(12);
        File::ensureDirectoryExists($public.'/build');
        File::put($public.'/build/manifest.json', (string) json_encode(collect(['resources/css/app.css', 'resources/css/site.css', 'resources/js/app.js'])
            ->mapWithKeys(fn (string $src): array => [$src => ['file' => 'assets/'.basename($src), 'src' => $src, 'isEntry' => true]])));
        app()->usePublicPath($public);
        // A running `npm run dev` leaves `public/hot`, and Vite then adds its client script to every page.
        Vite::useHotFile(storage_path('framework/testing/vite.hot'));
        // Livewire injects its scripts into a response only after a component rendered in the same request; one test
        // process serves every request, so a component an earlier test rendered is forgotten by hand.
        app('livewire')->flushState();

        $html = $this->get('/about')->assertOk()->getContent();

        expect($html)->not->toContain('wire:')
            ->and($html)->not->toMatch('/<script[^>]*\bsrc=/i')
            ->and($html)->not->toMatch('/livewire/i')
            ->and($html)->not->toContain('type="module"')
            ->and($html)->toContain('rich-text-content')
            ->and($html)->toContain(Vite::asset('resources/css/site.css'))
            ->and($html)->not->toContain(Vite::asset('resources/css/app.css'));

        File::deleteDirectory($public);
    });
});

describe('preview', function () {
    it('answers 404 for a draft, a scheduled and a trashed page to a guest and to a user who may not edit pages', function (string $state) {
        $entry = Entry::factory()->page()->inLocale('uk')->{$state === 'trashed' ? 'published' : $state}()->create(['slug' => 'secret', 'path' => 'secret']);

        if ($state === 'trashed') {
            $entry->delete();
        }

        $this->get('/secret')->assertNotFound();

        browseAs(User::factory()->withPermissions(['admin.access', 'pages.view', 'pages.create'])->create());
        $this->get('/secret')->assertNotFound();
    })->with(['draft', 'scheduled', 'trashed']);

    it('opens a draft for an editor under a strip, with noindex and no caching', function () {
        $draft = Entry::factory()->page()->draft()->inLocale('uk')->create(['title' => 'Чернетка', 'slug' => 'draft', 'path' => 'draft']);
        browseAs(pageEditor());

        $response = $this->get('/draft')->assertOk()
            ->assertSeeText('Чернетка')
            ->assertSee('data-test="preview-strip"', escape: false)
            ->assertSeeText(__('content.public.draft', locale: 'uk'))
            ->assertSee('<meta name="robots" content="noindex">', escape: false)
            ->assertSee('href="'.route('admin.content.edit', ['type' => 'pages', 'entry' => $draft->id]).'"', escape: false);

        expect($response->headers->get('Cache-Control'))->toContain('no-store');
    });

    it('names the date of a scheduled page in the site\'s timezone', function () {
        config(['admin.timezone' => 'Pacific/Auckland']);
        $scheduled = Entry::factory()->page()->scheduled()->inLocale('uk')->create(['slug' => 'soon', 'path' => 'soon']);
        browseAs(pageEditor());

        $this->get('/soon')->assertOk()
            ->assertSee('data-test="preview-strip"', escape: false)
            ->assertSeeText($scheduled->published_at->tz('Pacific/Auckland')->locale('uk')->isoFormat('LLL'));
    });

    it('still answers 404 for a trashed page to an editor', function () {
        $entry = Entry::factory()->page()->published()->inLocale('uk')->create(['slug' => 'gone', 'path' => 'gone']);
        $entry->delete();
        browseAs(pageEditor());

        $this->get('/gone')->assertNotFound();
    });

    it('shows an editor a small Edit link on a published page, and a guest neither the link nor a strip', function () {
        [$about] = aboutPages();
        $edit = route('admin.content.edit', ['type' => 'pages', 'entry' => $about->id]);

        $this->get('/about')->assertOk()
            ->assertDontSee('data-test="preview-strip"', escape: false)
            ->assertDontSee($edit, escape: false)
            ->assertDontSee('noindex', escape: false);

        browseAs(pageEditor());

        $this->get('/about')->assertOk()
            ->assertDontSee('data-test="preview-strip"', escape: false)
            ->assertDontSee('noindex', escape: false)
            ->assertSee('href="'.$edit.'"', escape: false);
    });
});

describe('languages', function () {
    it('lists only the public translations in active languages in hreflang, plus x-default', function () {
        [$about] = aboutPages();
        Entry::factory()->page()->draft()->translationOf($about, 'de')->create(['slug' => 'ueber-uns', 'path' => 'ueber-uns']);
        Entry::factory()->page()->published()->translationOf($about, 'pl')->create(['slug' => 'o-nas', 'path' => 'o-nas']);

        $this->get('/en/about-us')->assertOk()
            ->assertSee('<link rel="alternate" hreflang="uk" href="'.url('/about').'" />', escape: false)
            ->assertSee('<link rel="alternate" hreflang="en" href="'.url('/en/about-us').'" />', escape: false)
            ->assertSee('<link rel="alternate" hreflang="x-default" href="'.url('/about').'" />', escape: false)
            ->assertDontSee('<link rel="alternate" hreflang="de"', escape: false)
            ->assertDontSee('<link rel="alternate" hreflang="pl"', escape: false);
    });

    it('prints no hreflang for a page without public translations', function () {
        Entry::factory()->page()->published()->inLocale('uk')->create(['slug' => 'alone', 'path' => 'alone']);

        $this->get('/alone')->assertOk()->assertDontSee('<link rel="alternate"', escape: false);
    });

    it('leaves out x-default when the default language has no public translation', function () {
        $en = Entry::factory()->page()->published()->inLocale('en')->create(['slug' => 'news', 'path' => 'news']);
        Entry::factory()->page()->published()->translationOf($en, 'de')->create(['slug' => 'neues', 'path' => 'neues']);
        Entry::factory()->page()->draft()->translationOf($en, 'uk')->create(['slug' => 'novyny', 'path' => 'novyny']);

        $this->get('/en/news')->assertOk()
            ->assertSee('<link rel="alternate" hreflang="en"', escape: false)
            ->assertSee('<link rel="alternate" hreflang="de"', escape: false)
            ->assertDontSee('<link rel="alternate" hreflang="uk"', escape: false)
            ->assertDontSee('<link rel="alternate" hreflang="x-default"', escape: false);
    });

    it('links the switcher to a translation, and to that language\'s home page when there is none', function () {
        aboutPages();

        $switcher = $this->get('/about')->assertOk()->assertSee('data-test="language-switcher"', escape: false);

        $switcher->assertSee('href="'.url('/en/about-us').'"', escape: false)
            ->assertSee('href="'.url('/de').'"', escape: false)
            ->assertDontSee('href="'.url('/pl').'"', escape: false);
    });

    it('leads an editor to a translation that is not published yet, and a visitor to that language\'s home page', function () {
        aboutPages(en: ['status' => 'draft', 'published_at' => null]);

        $this->get('/about')->assertOk()
            ->assertSee('href="'.url('/en').'"', escape: false)
            ->assertDontSee('href="'.url('/en/about-us').'"', escape: false);

        browseAs(pageEditor());

        $this->get('/about')->assertOk()->assertSee('href="'.url('/en/about-us').'"', escape: false);
        $this->get('/en/about-us')->assertOk()->assertSee('data-test="preview-strip"', escape: false);
    });

    it('has no switcher on a site with one language', function () {
        publicLanguages(['uk' => true], 'uk');
        aboutPages();

        $this->get('/about')->assertOk()->assertDontSee('data-test="language-switcher"', escape: false);
    });

    it('serves /de/about as the German site\'s page', function () {
        [$about] = aboutPages();
        Entry::factory()->page()->published()->translationOf($about, 'de')->create(['title' => 'Über uns', 'slug' => 'about', 'path' => 'about']);

        expect(routeNameFor('/de/about'))->toBe('localized.content');

        $this->get('/de/about')->assertOk()->assertSee('<html lang="de"', escape: false)->assertSeeText('Über uns');
    });

    it('answers 404 in a language that is off or not added, even for a page in it', function () {
        [$about] = aboutPages();
        Entry::factory()->page()->published()->translationOf($about, 'pl')->create(['slug' => 'o-nas', 'path' => 'o-nas']);

        $this->get('/pl/o-nas')->assertNotFound();
        $this->get('/fr/about')->assertNotFound();
        $this->get('/uk/about')->assertStatus(301)->assertRedirect(url('/about'));
    });

    it('opens a page in a switched-off language only as a preview for an editor', function () {
        [$about] = aboutPages();
        Entry::factory()->page()->published()->translationOf($about, 'pl')->create(['title' => 'O nas', 'slug' => 'o-nas', 'path' => 'o-nas']);
        Entry::factory()->page()->draft()->inLocale('pl')->create(['slug' => 'szkic', 'path' => 'szkic']);

        // A visitor, with or without a session, and a signed-in user who may not edit: 404.
        $this->get('/pl/o-nas')->assertNotFound();
        $this->withCookie((string) config('session.cookie'), 'a-session')->get('/pl/o-nas')->assertNotFound();
        browseAs(User::factory()->withPermissions(['admin.access', 'pages.view'])->create());
        $this->get('/pl/o-nas')->assertNotFound();

        browseAs(pageEditor());

        $this->get('/pl/o-nas')->assertOk()
            ->assertSee('O nas')
            ->assertSee('data-test="preview-strip"', escape: false)
            ->assertSee(__('content.public.language_off'))
            ->assertSee('<meta name="robots" content="noindex">', escape: false)
            ->assertHeader('Cache-Control', 'no-store, private');
        $this->get('/pl/szkic')->assertOk()->assertSee(__('content.public.draft'));

        // Only content records: the language's home page, a miss, and a language that is not added stay 404.
        $this->get('/pl')->assertNotFound();
        $this->get('/pl/nie-ma')->assertNotFound();
        $this->get('/fr/about')->assertNotFound();
    });

    it('lets an editor signed in by "remember me" alone preview a draft', function () {
        hiddenContent();

        // No session cookie and no remember cookie: the same 404 as a miss, before the session.
        $this->actingAs(pageEditor())->get('/draft')->assertNotFound();

        $this->actingAs(pageEditor())->withCookie('remember_web_59ba36addc2b2f9401580f014c7f58ea4e30989d', 'recaller')
            ->get('/draft')->assertOk()->assertSee('data-test="preview-strip"', escape: false);

        // A visitor who sends such a cookie without being signed in still gets the 404.
        auth()->logout();
        $this->withCookie('remember_web_x', 'forged')->get('/draft')->assertNotFound();
    });

    it('does not let the alternates of one page leak into the next request', function () {
        aboutPages();

        $this->get('/en/about-us')->assertOk();
        $this->get('/en')->assertOk()
            ->assertSee('<link rel="alternate" hreflang="de" href="'.url('/de').'" />', escape: false);
    });
});

describe('redirects', function () {
    it('answers 301 from an old path to the current address, in both languages', function () {
        [$about, $aboutUs] = aboutPages();
        EntryRedirect::query()->create(['type' => 'pages', 'locale' => 'uk', 'path' => 'pro-nas', 'entry_id' => $about->id]);
        EntryRedirect::query()->create(['type' => 'pages', 'locale' => 'en', 'path' => 'who-we-are', 'entry_id' => $aboutUs->id]);

        $response = $this->get('/pro-nas')->assertStatus(301)->assertRedirect(url('/about'));
        expect($response->headers->get('Cache-Control'))->toContain('no-cache');

        $this->get('/en/who-we-are')->assertStatus(301)->assertRedirect(url('/en/about-us'));
        $this->get('/who-we-are')->assertNotFound();
    });

    it('redirects to a page that is not public only for an editor', function () {
        $draft = Entry::factory()->page()->draft()->inLocale('uk')->create(['slug' => 'new', 'path' => 'new']);
        EntryRedirect::query()->create(['type' => 'pages', 'locale' => 'uk', 'path' => 'old', 'entry_id' => $draft->id]);

        $this->get('/old')->assertNotFound();

        browseAs(pageEditor());
        $this->get('/old')->assertStatus(301)->assertRedirect(url('/new'));
    });

    it('follows a slug changed through the form\'s rules', function () {
        [$about] = aboutPages();
        app(EntryPaths::class)->move($about, 'company');
        $about->save();

        $this->get('/about')->assertStatus(301)->assertRedirect(url('/company'));
        $this->get('/company')->assertOk();
    });
});

describe('routing', function () {
    it('leaves every other route as it was', function () {
        $livewireUpdate = app('livewire')->getUpdateUri();

        expect(routeNameFor('/'))->toBe('home')
            ->and(routeNameFor('/en'))->toBe('localized.home')
            ->and(routeNameFor('/admin'))->toBe('admin.dashboard')
            ->and(routeNameFor('/login'))->toBe('login')
            ->and(routeNameFor('/dashboard'))->toBe('dashboard')
            ->and(routeNameFor('/settings/profile'))->toBe('profile.edit')
            ->and(routeNameFor('/media/01JC0000000000000000000000/photo.webp'))->toBe('media.show')
            ->and(routeNameFor('/storage/media/x.webp'))->toBe('media.static')
            ->and(Route::getRoutes()->match(Request::create('/up'))->uri())->toBe('up')
            ->and(routeNameFor($livewireUpdate, 'POST'))->toBe('default-livewire.update')
            ->and(routeNameFor('/about'))->toBe('content')
            ->and(routeNameFor('/en/about/team'))->toBe('localized.content');

        $this->get('/')->assertOk()->assertSee('<html lang="uk"', escape: false);
        $this->get('/en')->assertOk()->assertSee('<html lang="en"', escape: false);
        $this->get('/login')->assertOk();
        $this->get('/admin')->assertRedirect(route('login'));
        $this->get('/up')->assertOk();
    });

    it('keeps the reserved slugs as they were', function () {
        $reserved = app(ReservedSlugs::class)->all();

        expect($reserved)->toContain('admin', 'login', 'settings', 'media', 'storage', 'dashboard', 'up', 'uk', 'en')
            ->and(array_filter($reserved, fn (string $slug): bool => str_contains($slug, '{')))->toBe([])
            ->and(app(ReservedSlugs::class)->contains('about'))->toBeFalse();
    });

    it('builds the address of a record from the route, without a query', function () {
        app(ContentTypes::class)->register(new ProductsType);
        [$about, $aboutUs] = aboutPages();
        $mug = Entry::factory()->create(['type' => 'products', 'locale' => 'en', 'slug' => 'mug', 'path' => 'mug']);

        $count = publicQueriesOf(function () use ($about, $aboutUs, $mug, &$urls): void {
            $urls = [$about->url(false), $aboutUs->url(), $mug->url(false)];
        });

        expect($count)->toBe(0)
            ->and($urls)->toBe(['/about', url('/en/about-us'), '/en/products/mug'])
            ->and($aboutUs->url())->toBe(app(ContentLocales::class)->route('content', ['path' => 'about-us'], 'en'));
    });

    it('still serializes the routes for the route cache, and keeps the content routes last there', function () {
        $routes = Route::getRoutes();

        foreach ($routes->getRoutes() as $route) {
            $route->prepareForSerialization();
        }

        $compiled = $routes->compile();
        $cached = (new CompiledRouteCollection($compiled['compiled'], $compiled['attributes']))
            ->setRouter(app('router'))
            ->setContainer(app());

        expect($cached->match(Request::create('/admin'))->getName())->toBe('admin.dashboard')
            ->and($cached->match(Request::create('/login'))->getName())->toBe('login')
            ->and($cached->match(Request::create('/de/about'))->getName())->toBe('localized.content')
            ->and($cached->match(Request::create('/de'))->getName())->toBe('localized.home')
            ->and($cached->match(Request::create('/about/team'))->getName())->toBe('content');
    });
});

describe('addresses that cannot be content', function () {
    it('leaves them to the router\'s own 404, with no query and no session', function (string $uri) {
        aboutPages();

        expect(fn () => Route::getRoutes()->match(Request::create($uri)))->toThrow(NotFoundHttpException::class)
            ->and(publicQueriesOf(fn () => $this->get($uri)->assertNotFound()))->toBe(0)
            ->and(session()->has(ContentLocales::SESSION_KEY))->toBeFalse();
    })->with([
        '/favicon.ico',
        '/wp-login.php',
        '/.env',
        '/about_us',
        '/'.rawurlencode('про-нас'),
        '/about//team',
        '/About',
        '/en/favicon.ico',
    ]);

    it('answers a GET to an address that only has a POST route from the content route, with 404', function () {
        expect(routeNameFor('/logout'))->toBe('content');

        $this->get('/logout')->assertNotFound();
    });

    it('never matches a path with a trailing newline when called directly', function () {
        aboutPages();

        expect(app(ContentRouter::class)->lookup('uk', "about\n"))->toBeNull()
            ->and(app(ContentRouter::class)->resolve('uk', "about\n", null))->toBeNull()
            ->and(app(ContentRouter::class)->lookup('uk', 'about'))->not->toBeNull();
    });

    it('builds the address of every path the application can store', function () {
        app(ContentTypes::class)->register(new ProductsType);
        $deep = 'a/b1/2024/x-y-z-9/'.str_repeat('long-slug-', 10).'end/q';
        $long = implode('-', array_fill(0, 63, 'ab1')).'-x';
        $page = Entry::factory()->page()->inLocale('en')->create(['slug' => 'q', 'path' => $deep]);
        $product = Entry::factory()->create(['type' => 'products', 'locale' => 'uk', 'slug' => $long, 'path' => $long]);

        expect(strlen($long))->toBeLessThanOrEqual(255)
            ->and($page->url(false))->toBe('/en/'.$deep)
            ->and($product->url(false))->toBe('/products/'.$long);
    });
});

describe('cost', function () {
    it('makes at most five queries more than the home page for a page with translations and images', function () {
        $home = publicQueriesOf(fn () => $this->get('/')->assertOk());

        $image = Media::factory()->image()->create();
        $inBody = Media::factory()->image()->create();
        aboutPages(['image_id' => $image->id, 'body' => '<p>Text</p><img data-media-id="'.$inBody->id.'" alt="x">']);
        $about = Entry::query()->where('path', 'about')->firstOrFail();
        Entry::factory()->page()->published()->translationOf($about, 'de')->create(['slug' => 'ueber-uns', 'path' => 'ueber-uns']);

        $page = publicQueriesOf(fn () => $this->get('/about')->assertOk()->assertSee('<link rel="alternate" hreflang="de"', escape: false));

        expect($page - $home)->toBeLessThanOrEqual(5)
            ->and(publicQueriesOf(fn () => $this->get('/about')->assertOk(), ['entries', 'entry_redirects', 'media']))->toBeLessThanOrEqual(4);
    });

    it('makes one query for a plain page on a one-language site', function () {
        publicLanguages(['uk' => true], 'uk');
        aboutPages(['body' => '<p>Text</p>']);

        expect(publicQueriesOf(fn () => $this->get('/about')->assertOk()))->toBe(1);
    });

    it('answers a hidden record with the same 404 as a missing path', function () {
        Entry::factory()->page()->draft()->inLocale('uk')->create(['slug' => 'hidden', 'path' => 'hidden']);

        $hidden = $this->get('/hidden')->assertNotFound();
        $missing = $this->get('/missing')->assertNotFound();

        expect($hidden->headers->get('Cache-Control'))->toBe($missing->headers->get('Cache-Control'))
            ->and($hidden->getContent())->toBe($missing->getContent());
    });

    it('answers a slug-shaped miss with 404 before the session starts, in at most two queries', function (string $uri) {
        aboutPages();

        $response = null;
        $queries = publicQueriesOf(function () use ($uri, &$response): void {
            $response = $this->get($uri)->assertNotFound();
        });

        expect($queries)->toBeLessThanOrEqual(2)
            ->and($response->headers->getCookies())->toBe([])
            ->and(session()->has(ContentLocales::SESSION_KEY))->toBeFalse();
    })->with(['/wp-admin', '/xmlrpc', '/en/no-such-page', '/about/no-such-page']);

    it('runs the content lookup before the session on both content routes', function (string $name) {
        $middleware = app('router')->gatherRouteMiddleware(Route::getRoutes()->getByName($name));

        expect(array_search(LookUpContent::class, $middleware, true))
            ->toBeLessThan(array_search(StartSession::class, $middleware, true))
            ->and(array_search(SetContentLocale::class, $middleware, true))
            ->toBeGreaterThan(array_search(StartSession::class, $middleware, true));
    })->with(['content', 'localized.content']);

    it('gives a cookieless guest the same 404 before the session for hidden content as for a miss', function () {
        hiddenContent();

        $responses = [];
        $queries = [];

        foreach (['/draft', '/scheduled', '/old-draft', '/missing'] as $uri) {
            $queries[$uri] = publicQueriesOf(function () use ($uri, &$responses): void {
                $responses[$uri] = $this->get($uri)->assertNotFound();
            }, ['entries', 'entry_redirects', 'media']);
        }

        $shapes = array_map(fn ($response): array => [
            $response->getStatusCode(),
            collect($response->headers->all())->except('date')->all(),
            $response->getContent(),
        ], $responses);

        expect(array_unique(array_map(serialize(...), $shapes)))->toHaveCount(1)
            ->and(collect($responses)->every(fn ($response): bool => $response->headers->getCookies() === []))->toBeTrue()
            ->and(session()->has(ContentLocales::SESSION_KEY))->toBeFalse()
            ->and($queries)->toBe(['/draft' => 1, '/scheduled' => 1, '/old-draft' => 2, '/missing' => 2]);
    });

    it('answers hidden content and a miss alike after the session for a guest who has a session', function () {
        hiddenContent();
        $this->withCookie((string) config('session.cookie'), 'a-session');

        $responses = [];
        $queries = [];

        foreach (['/draft', '/scheduled', '/old-draft', '/missing'] as $uri) {
            $queries[$uri] = publicQueriesOf(function () use ($uri, &$responses): void {
                $responses[$uri] = $this->get($uri)->assertNotFound();
            }, ['entries', 'entry_redirects', 'media']);
        }

        // Cookie values are encrypted afresh on every response, so cookies compare by name.
        $shapes = array_map(fn ($response): array => [
            $response->getStatusCode(),
            collect($response->headers->all())->except(['date', 'set-cookie'])->all(),
            collect($response->headers->getCookies())->map(fn ($cookie): string => $cookie->getName())->sort()->values()->all(),
            $response->getContent(),
        ], $responses);

        expect(array_unique(array_map(serialize(...), $shapes)))->toHaveCount(1)
            ->and($shapes['/missing'][2])->toContain((string) config('session.cookie'))
            ->and($queries)->toBe(['/draft' => 1, '/scheduled' => 1, '/old-draft' => 2, '/missing' => 2]);
    });

    it('starts the session for a public page and a redirect to one, also without a cookie', function () {
        [$about] = aboutPages();
        EntryRedirect::query()->create(['type' => 'pages', 'locale' => 'uk', 'path' => 'pro-nas', 'entry_id' => $about->id]);

        $sessionCookie = fn ($response): bool => collect($response->headers->getCookies())->contains(fn ($cookie): bool => $cookie->getName() === config('session.cookie'));

        expect($sessionCookie($this->get('/about')->assertOk()))->toBeTrue()
            ->and(session(ContentLocales::SESSION_KEY))->toBe('uk')
            ->and($sessionCookie($this->get('/pro-nas')->assertRedirect('/about')))->toBeTrue();
    });

    it('still shows an editor with a session the draft and the redirect to it', function () {
        hiddenContent();
        browseAs(pageEditor());

        $this->get('/draft')->assertOk()->assertSee('data-test="preview-strip"', escape: false);
        $this->get('/old-draft')->assertRedirect('/draft');
    });

    it('answers a miss and a redirect with at most two content queries', function () {
        [$about] = aboutPages();
        EntryRedirect::query()->create(['type' => 'pages', 'locale' => 'uk', 'path' => 'pro-nas', 'entry_id' => $about->id]);

        expect(publicQueriesOf(fn () => $this->get('/nothing-here')->assertNotFound(), ['entries', 'entry_redirects']))->toBeLessThanOrEqual(2)
            ->and(publicQueriesOf(fn () => $this->get('/pro-nas')->assertStatus(301), ['entries', 'entry_redirects']))->toBeLessThanOrEqual(2);
    });
});

describe('admin links', function () {
    it('opens the page from the list\'s View on site and a draft from the form\'s Preview', function () {
        [$about] = aboutPages();
        $draft = Entry::factory()->page()->draft()->inLocale('uk')->create(['title' => 'Чернетка', 'slug' => 'draft', 'path' => 'draft']);
        browseAs($editor = pageEditor());

        Livewire::withQueryParams(['locale' => 'uk'])->test('pages::admin.content.index', ['type' => 'pages'])
            ->assertSeeHtml('href="'.$about->url().'"');
        Livewire::test('pages::admin.content.edit', ['type' => 'pages', 'entry' => $draft])
            ->assertSee(__('content.form.preview'))
            ->assertSeeHtml('href="'.$draft->url().'"');

        $this->get($about->url())->assertOk()->assertSeeText('Про нас');
        $this->get($draft->url())->assertOk()->assertSee('data-test="preview-strip"', escape: false);
    });
});

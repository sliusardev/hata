<?php

use App\Http\Middleware\LookUpContent;
use App\Http\Middleware\SetContentLocale;
use App\Models\Entry;
use App\Models\EntryRedirect;
use App\Models\Media;
use App\Models\User;
use App\Support\Content\ContentRouter;
use App\Support\Content\ContentTypes;
use App\Support\Content\EntryPaths;
use App\Support\Localization\ContentLocales;
use App\Support\Localization\ReservedSlugs;
use App\Support\Settings\SiteSettings;
use Illuminate\Http\Request;
use Illuminate\Routing\CompiledRouteCollection;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Vite;
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

    it('opens a record under its type\'s base, and leaves the bare base to the lists of a later part', function () {
        app(ContentTypes::class)->register(new ProductsType);
        Entry::factory()->published()->create(['type' => 'products', 'locale' => 'uk', 'title' => 'Чашка', 'slug' => 'mug', 'path' => 'mug']);

        $this->get('/products/mug')->assertOk()->assertSeeText('Чашка');
        $this->get('/products')->assertNotFound();
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
    });
});

describe('preview', function () {
    it('answers 404 for a draft, a scheduled and a trashed page to a guest and to a user who may not edit pages', function (string $state) {
        $entry = Entry::factory()->page()->inLocale('uk')->{$state === 'trashed' ? 'published' : $state}()->create(['slug' => 'secret', 'path' => 'secret']);

        if ($state === 'trashed') {
            $entry->delete();
        }

        $this->get('/secret')->assertNotFound();

        $this->actingAs(User::factory()->withPermissions(['admin.access', 'pages.view', 'pages.create'])->create());
        $this->get('/secret')->assertNotFound();
    })->with(['draft', 'scheduled', 'trashed']);

    it('opens a draft for an editor under a strip, with noindex and no caching', function () {
        $draft = Entry::factory()->page()->draft()->inLocale('uk')->create(['title' => 'Чернетка', 'slug' => 'draft', 'path' => 'draft']);
        $this->actingAs(pageEditor());

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
        $this->actingAs(pageEditor());

        $this->get('/soon')->assertOk()
            ->assertSee('data-test="preview-strip"', escape: false)
            ->assertSeeText($scheduled->published_at->tz('Pacific/Auckland')->locale('uk')->isoFormat('LLL'));
    });

    it('still answers 404 for a trashed page to an editor', function () {
        $entry = Entry::factory()->page()->published()->inLocale('uk')->create(['slug' => 'gone', 'path' => 'gone']);
        $entry->delete();
        $this->actingAs(pageEditor());

        $this->get('/gone')->assertNotFound();
    });

    it('shows an editor a small Edit link on a published page, and a guest neither the link nor a strip', function () {
        [$about] = aboutPages();
        $edit = route('admin.content.edit', ['type' => 'pages', 'entry' => $about->id]);

        $this->get('/about')->assertOk()
            ->assertDontSee('data-test="preview-strip"', escape: false)
            ->assertDontSee($edit, escape: false)
            ->assertDontSee('noindex', escape: false);

        $this->actingAs(pageEditor());

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

        $this->actingAs(pageEditor());
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

    it('starts the session for a page, a redirect and a hidden record, and keeps their answers', function () {
        [$about] = aboutPages();
        EntryRedirect::query()->create(['type' => 'pages', 'locale' => 'uk', 'path' => 'pro-nas', 'entry_id' => $about->id]);
        Entry::factory()->page()->draft()->inLocale('uk')->create(['slug' => 'hidden', 'path' => 'hidden']);

        $sessionCookie = fn ($response): bool => collect($response->headers->getCookies())->contains(fn ($cookie): bool => $cookie->getName() === config('session.cookie'));

        expect($sessionCookie($this->get('/about')->assertOk()))->toBeTrue()
            ->and(session(ContentLocales::SESSION_KEY))->toBe('uk')
            ->and($sessionCookie($this->get('/pro-nas')->assertRedirect('/about')))->toBeTrue()
            ->and($sessionCookie($this->get('/hidden')->assertNotFound()))->toBeTrue();
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
        $this->actingAs($editor = pageEditor());

        Livewire::withQueryParams(['locale' => 'uk'])->test('pages::admin.content.index', ['type' => 'pages'])
            ->assertSeeHtml('href="'.$about->url().'"');
        Livewire::test('pages::admin.content.edit', ['type' => 'pages', 'entry' => $draft])
            ->assertSee(__('content.form.preview'))
            ->assertSeeHtml('href="'.$draft->url().'"');

        $this->get($about->url())->assertOk()->assertSeeText('Про нас');
        $this->get($draft->url())->assertOk()->assertSee('data-test="preview-strip"', escape: false);
    });
});

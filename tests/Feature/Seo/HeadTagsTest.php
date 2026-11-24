<?php

use App\Models\User;
use Hata\Models\Entry;
use Hata\Models\Media;
use Hata\Support\Content\ContentTypes;
use Hata\Support\Content\Types\CategoriesType;
use Hata\Support\Content\Types\PostsType;
use Hata\Support\Seo\PageMeta;
use Hata\Support\Settings\SiteSettings;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    seedRolesAndPermissions();

    app(SiteSettings::class)->put([
        'content_locales' => [['code' => 'uk', 'active' => true], ['code' => 'en', 'active' => true]],
        'content_default_locale' => 'uk',
    ]);
});

function headPage(array $attributes = []): Entry
{
    return Entry::factory()->page()->published()->inLocale('uk')->create(['title' => 'Про нас', 'slug' => 'about', 'path' => 'about', ...$attributes]);
}

it('prints description, canonical, Open Graph and Twitter tags of a page', function () {
    $image = Media::factory()->image(1200, 630)->create();
    headPage(['seo_description' => 'About us', 'image_id' => $image->id]);

    $this->get('/about')->assertOk()
        ->assertSee('<title>Про нас - '.config('app.name').'</title>', escape: false)
        ->assertSee('<meta name="description" content="About us">', escape: false)
        ->assertSee('<link rel="canonical" href="'.url('/about').'">', escape: false)
        ->assertSee('<meta property="og:site_name" content="'.config('app.name').'">', escape: false)
        ->assertSee('<meta property="og:title" content="Про нас">', escape: false)
        ->assertSee('<meta property="og:description" content="About us">', escape: false)
        ->assertSee('<meta property="og:url" content="'.url('/about').'">', escape: false)
        ->assertSee('<meta property="og:type" content="website">', escape: false)
        ->assertSee('<meta property="og:locale" content="uk">', escape: false)
        ->assertSee('<meta property="og:image" content="'.url('/').'/', escape: false)
        ->assertSee('<meta property="og:image:width" content="1200">', escape: false)
        ->assertSee('<meta property="og:image:height" content="630">', escape: false)
        ->assertSee('<meta name="twitter:card" content="summary_large_image">', escape: false);
});

it('uses the SEO title for the title and og:title', function () {
    headPage(['seo_title' => 'Custom SEO']);

    $this->get('/about')->assertOk()
        ->assertSee('<title>Custom SEO</title>', escape: false)
        ->assertSee('<meta property="og:title" content="Custom SEO">', escape: false);
});

it('falls back from the description to the excerpt, the body and the site description', function () {
    app(SiteSettings::class)->put(['site_description' => ['uk' => 'Опис сайту']]);

    headPage(['excerpt' => 'The excerpt', 'body' => '<p>Body text</p>']);
    $this->get('/about')->assertSee('<meta name="description" content="The excerpt">', escape: false);

    Entry::query()->update(['excerpt' => null]);
    $this->get('/about')->assertSee('<meta name="description" content="Body text">', escape: false);

    Entry::query()->update(['body' => null]);
    $this->get('/about')->assertSee('<meta name="description" content="Опис сайту">', escape: false);
});

it('escapes a description that decodes to markup', function () {
    headPage(['seo_description' => '&lt;script&gt;alert(1)&lt;/script&gt;']);

    $this->get('/about')->assertOk()
        ->assertSee('<meta name="description" content="&lt;script&gt;alert(1)&lt;/script&gt;">', escape: false)
        ->assertDontSee('<script>alert(1)</script>', escape: false);
});

it('says article for a post and website for a page', function () {
    app(ContentTypes::class)->register(new PostsType);
    $post = Entry::factory()->published()->inLocale('uk')->create(['type' => 'posts', 'title' => 'Chair', 'slug' => 'chair', 'path' => 'chair']);

    $this->get($post->url(false))->assertOk()
        ->assertSee('<meta property="og:type" content="article">', escape: false);
});

it('lists the other languages as og:locale:alternate', function () {
    $about = headPage();
    Entry::factory()->page()->published()->translationOf($about, 'en')->create(['title' => 'About us', 'slug' => 'about-us', 'path' => 'about-us']);

    $this->get('/about')->assertOk()
        ->assertSee('<meta property="og:locale" content="uk">', escape: false)
        ->assertSee('<meta property="og:locale:alternate" content="en">', escape: false)
        ->assertSee('<link rel="alternate" hreflang="en"', escape: false);
});

it('marks a draft preview noindex with no canonical and no Open Graph', function () {
    $editor = User::factory()->withRole('editor')->create();
    Entry::factory()->page()->draft()->inLocale('uk')->create(['title' => 'Чернетка', 'slug' => 'draft', 'path' => 'draft']);

    $this->actingAs($editor)->withCookie((string) config('session.cookie'), 'a-session')->get('/draft')->assertOk()
        ->assertSee('<meta name="robots" content="noindex">', escape: false)
        ->assertDontSee('rel="canonical"', escape: false)
        ->assertDontSee('og:', escape: false)
        ->assertDontSee('twitter:card', escape: false);
});

it('builds the canonical from the record, not from the request', function () {
    headPage();

    foreach (['/about/', '/about?utm_source=x'] as $url) {
        $this->get($url)->assertOk()->assertSee('<link rel="canonical" href="'.url('/about').'">', escape: false);
    }
});

it('uses the site image on a list and a term and keeps ?page=N in their canonical', function () {
    $site = Media::factory()->image()->create();
    config(['hata.seo.image' => $site->id]);
    app(ContentTypes::class)->register(new PostsType)->register(new CategoriesType);

    $post = Entry::factory()->published()->inLocale('uk')->create(['type' => 'posts', 'title' => 'P', 'slug' => 'p', 'path' => 'p']);
    $term = Entry::factory()->published()->inLocale('uk')->create(['type' => 'categories', 'title' => 'News', 'slug' => 'news', 'path' => 'news']);
    $list = url(dirname($post->url(false)));
    $label = __('hata::content.types.posts.plural', [], 'uk');
    $termUrl = $term->url();

    $this->get($list)->assertOk()
        ->assertSee('<link rel="canonical" href="'.$list.'">', escape: false)
        ->assertSee('<meta property="og:image" content="'.$site->url().'">', escape: false)
        ->assertSee('<meta property="og:title" content="'.$label.'">', escape: false)
        ->assertSee('<title>'.$label.' - '.config('app.name').'</title>', escape: false);

    $this->get($termUrl)->assertOk()
        ->assertSee('<link rel="canonical" href="'.$termUrl.'">', escape: false)
        ->assertSee('<meta property="og:image" content="'.$site->url().'">', escape: false)
        ->assertSee('<meta property="og:type" content="website">', escape: false);

    $this->get($termUrl.'?page=2&utm=x')->assertOk()
        ->assertSee('<link rel="canonical" href="'.$termUrl.'?page=2">', escape: false);
    $this->get($termUrl.'?page=1')->assertOk()
        ->assertSee('<link rel="canonical" href="'.$termUrl.'">', escape: false);
    $this->get($list.'?page=2')->assertOk()
        ->assertSee('<link rel="canonical" href="'.$list.'?page=2">', escape: false);
});

it('uses the site image for an SVG record image and none when there is no site image', function () {
    $svg = Media::factory()->image()->create(['name' => 'a.svg', 'path' => 'media/a.svg', 'mime' => 'image/svg+xml', 'uploaded_extension' => 'svg']);
    headPage(['image_id' => $svg->id]);

    $this->get('/about')->assertOk()
        ->assertDontSee('og:image', escape: false)
        ->assertSee('<meta name="twitter:card" content="summary">', escape: false);
});

it('uses the site image for a record whose own image is an SVG', function () {
    $svg = Media::factory()->image()->create(['name' => 'a.svg', 'path' => 'media/a.svg', 'mime' => 'image/svg+xml', 'uploaded_extension' => 'svg']);
    $site = Media::factory()->image()->create();
    config(['hata.seo.image' => $site->id]);
    headPage(['image_id' => $svg->id]);

    $this->get('/about')->assertOk()->assertSee('<meta property="og:image" content="'.$site->url().'">', escape: false);
});

it('prints the title from @hataHead in a view outside the site layout', function () {
    app(PageMeta::class)->setTitle('Standalone');

    expect(Blade::render('@hataHead'))->toContain('<title>Standalone</title>');
});

it('costs one query more for the site image when the page has no image of its own', function () {
    $site = Media::factory()->image()->create();
    $own = Media::factory()->image()->create();
    headPage(['image_id' => $own->id]);
    Entry::factory()->page()->published()->inLocale('uk')->create(['title' => 'Plain', 'slug' => 'plain', 'path' => 'plain']);

    $count = function (string $url): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->get($url)->assertOk();
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $queries;
    };

    config(['hata.seo.image' => $site->id]);
    $withOwn = $count('/about');
    $withoutOwn = $count('/plain');

    // The own image is one query too (the page's image relation), so compare against a page without any image and no site image.
    config(['hata.seo.image' => null]);
    $plainNoSite = $count('/plain');

    expect($withoutOwn - $plainNoSite)->toBe(1)
        ->and($withOwn)->toBe($plainNoSite + 1);
});

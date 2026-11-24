<?php

use App\Models\User;
use Hata\Actions\Settings\SetContentTypeActive;
use Hata\Models\Entry;
use Hata\Support\Content\ContentTypes;
use Hata\Support\Content\Types\CategoriesType;
use Hata\Support\Content\Types\PostsType;
use Hata\Support\Content\Types\TagsType;
use Hata\Support\Settings\SiteSettings;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    app(ContentTypes::class)->register(new PostsType)->register(new CategoriesType)->register(new TagsType);
    seedRolesAndPermissions();

    app(SiteSettings::class)->put([
        'content_locales' => [
            ['code' => 'en', 'active' => true],
            ['code' => 'uk', 'active' => true],
            ['code' => 'de', 'active' => true],
            ['code' => 'fr', 'active' => false],
        ],
        'content_default_locale' => 'en',
    ]);
});

/**
 * The sitemap's body.
 */
function sitemap(): string
{
    return test()->get('/sitemap.xml')->assertOk()->streamedContent();
}

/**
 * @return list<string> every <loc>
 */
function sitemapLocs(string $xml): array
{
    preg_match_all('#<loc>([^<]+)</loc>#', $xml, $matches);

    return $matches[1];
}

/**
 * @param  array<string, mixed>  $attributes
 */
function sitemapEntry(string $type, string $slug, string $locale = 'en', array $attributes = [], string $state = 'published'): Entry
{
    return Entry::factory()->{$state}()->create(['type' => $type, 'locale' => $locale, 'slug' => $slug, 'path' => $slug, ...$attributes]);
}

it('answers with the headers of a cacheable xml file and no cookie', function () {
    $response = $this->get('/sitemap.xml')->assertOk()->assertCookieMissing((string) config('session.cookie'));

    expect($response->headers->get('Content-Type'))->toBe('application/xml; charset=utf-8')
        ->and($response->headers->get('Cache-Control'))->toContain('public')->toContain('max-age=3600');
});

it('lists the home of every active language with alternates between them', function () {
    $xml = sitemap();

    expect(sitemapLocs($xml))->toBe([url('/'), url('/uk'), url('/de')])
        ->and($xml)->toContain('<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" xmlns:xhtml="http://www.w3.org/1999/xhtml">')
        ->and($xml)->toContain('<xhtml:link rel="alternate" hreflang="uk" href="'.url('/uk').'"/>')
        ->and($xml)->toContain('<xhtml:link rel="alternate" hreflang="en" href="'.url('/').'"/>')
        ->and($xml)->not->toContain('/fr');
});

it('lists a page with its translation as two urls with mutual alternates', function () {
    $about = sitemapEntry('pages', 'about');
    sitemapEntry('pages', 'pro-nas', 'uk', ['entry_group_id' => $about->entry_group_id]);

    $xml = sitemap();

    expect(sitemapLocs($xml))->toContain(url('/about'), url('/uk/pro-nas'))
        ->and(substr_count($xml, '<xhtml:link rel="alternate" hreflang="en" href="'.url('/about').'"/>'))->toBe(2)
        ->and(substr_count($xml, '<xhtml:link rel="alternate" hreflang="uk" href="'.url('/uk/pro-nas').'"/>'))->toBe(2)
        ->and($xml)->toContain('<lastmod>'.$about->updated_at->utc()->toAtomString().'</lastmod>');
});

it('leaves out whatever is not public', function () {
    $draft = sitemapEntry('pages', 'draft', state: 'draft');
    $scheduled = sitemapEntry('pages', 'scheduled', state: 'scheduled');
    $trashed = sitemapEntry('pages', 'trashed');
    $trashed->delete();
    sitemapEntry('pages', 'francais', 'fr');
    sitemapEntry('pages', 'live');

    $locs = sitemapLocs(sitemap());

    expect($locs)->toContain(url('/live'))->not->toContain(url('/draft'), url('/scheduled'), url('/trashed'), url('/fr/francais'));
});

it('leaves out a switched-off type, its list and the terms that only it filed', function () {
    $post = sitemapEntry('posts', 'hello');
    $tag = sitemapEntry('tags', 'news');
    DB::table('entry_terms')->insert(['entry_id' => $post->id, 'term_id' => $tag->id]);

    expect(sitemapLocs(sitemap()))->toContain(url('/posts/hello'), url('/posts'), url('/tags/news'));

    app(SetContentTypeActive::class)->handle(User::factory()->withRole('admin')->create(), 'posts', false);

    expect(sitemapLocs(sitemap()))->not->toContain(url('/posts/hello'), url('/posts'), url('/tags/news'));
});

it('lists a term only while a public record of an active type is filed under it', function () {
    $draft = sitemapEntry('posts', 'unseen', state: 'draft');
    $live = sitemapEntry('posts', 'seen');
    $onlyDraft = sitemapEntry('tags', 'only-draft');
    $withLive = sitemapEntry('tags', 'with-live');
    sitemapEntry('tags', 'empty');
    DB::table('entry_terms')->insert([
        ['entry_id' => $draft->id, 'term_id' => $onlyDraft->id],
        ['entry_id' => $live->id, 'term_id' => $withLive->id],
    ]);

    expect(sitemapLocs(sitemap()))->toContain(url('/tags/with-live'))->not->toContain(url('/tags/only-draft'), url('/tags/empty'));
});

it('lists a type list with its newest record and skips it without public records', function () {
    expect(sitemapLocs(sitemap()))->not->toContain(url('/posts'));

    $this->travelTo(now()->subDays(3));
    sitemapEntry('posts', 'old');
    $this->travelTo(now()->addDays(2));
    $new = sitemapEntry('posts', 'new');
    $this->travelBack();

    $xml = sitemap();

    expect(sitemapLocs($xml))->toContain(url('/posts'))
        ->and($xml)->toMatch('#<loc>'.preg_quote(url('/posts'), '#').'</loc><lastmod>'.preg_quote($new->updated_at->utc()->toAtomString(), '#').'</lastmod>#');
});

it('lists the chosen home page as the home and not at its own address', function () {
    $home = sitemapEntry('pages', 'welcome');
    app(SiteSettings::class)->put(['home_page' => $home->id]);
    $this->travelTo(now()->addHour());
    $home->touch();
    $this->travelBack();

    $xml = sitemap();

    expect(sitemapLocs($xml))->toContain(url('/'))->not->toContain(url('/welcome'))
        ->and($xml)->toMatch('#<loc>'.preg_quote(url('/'), '#').'</loc><lastmod>'.preg_quote($home->fresh()->updated_at->utc()->toAtomString(), '#').'</lastmod>#');
});

it('answers 304 with no body and one query when nothing changed', function () {
    sitemapEntry('pages', 'about');

    $lastModified = $this->get('/sitemap.xml')->headers->get('Last-Modified');
    expect($lastModified)->not->toBeNull();

    DB::enableQueryLog();
    $response = $this->get('/sitemap.xml', ['If-Modified-Since' => $lastModified]);
    $queries = count(DB::getQueryLog());

    expect($response->getStatusCode())->toBe(304)
        ->and($response->getContent())->toBeIn(['', false])
        ->and($queries)->toBeLessThanOrEqual(1);
});

it('moves Last-Modified when a scheduled record goes live without a write', function () {
    $this->travelTo(now()->subDays(2));
    sitemapEntry('pages', 'old');
    $this->travelTo(now()->addDay());
    $soon = sitemapEntry('pages', 'soon', attributes: ['published_at' => now()->addDays(3)]);
    $this->travelBack();

    $before = $this->get('/sitemap.xml')->headers->get('Last-Modified');

    $this->travelTo($soon->published_at->addMinute());
    $after = $this->get('/sitemap.xml')->headers->get('Last-Modified');

    expect(strtotime($after))->toBeGreaterThan(strtotime($before))
        ->and(strtotime($after))->toBe($soon->published_at->getTimestamp());
});

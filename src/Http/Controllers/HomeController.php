<?php

namespace Hata\Http\Controllers;

use Hata\Support\Content\EntryPage;
use Hata\Support\Content\HomePage;
use Hata\Support\Localization\ContentLocales;
use Hata\Support\Seo\PageMeta;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * The site's address in every language: the chosen page when it is public in the language; the plain home (the site
 * name and description, indexed) where a page is chosen but not public; the Hata introduction (not indexed) while no
 * page is chosen at all. The alternates stay each active language's home address.
 */
class HomeController extends Controller
{
    public function __construct(
        private readonly ContentLocales $locales,
    ) {}

    /**
     * The scoped services (HomePage, PageMeta) arrive as arguments: a route keeps its controller for as long as the
     * router lives, which may be longer than one request.
     */
    public function __invoke(Request $request, HomePage $home, EntryPage $entryPage, PageMeta $meta): Response
    {
        $url = $this->locales->route('home');
        $chosen = $home->id() !== null;
        $record = $home->recordFor($this->locales->current()->code);

        if ($record !== null && $record->isPublic()) {
            return $entryPage->response($record, $request->user(), preview: false, languageOff: false, canonical: $url);
        }

        $meta
            ->setTitle((string) config('app.name'))
            ->setOgTitle((string) config('app.name'))
            ->setCanonical($url)
            // The introduction is Hata's, not the site's: kept out of search results (and out of the sitemap).
            ->setNoindex(! $chosen);

        $hint = null;

        if ($request->user()?->can('settings.edit')) {
            $hint = $chosen ? 'unpublished' : 'choose';
        }

        return response()->view($chosen ? 'hata::site.home-plain' : 'hata::site.home', ['hint' => $hint]);
    }
}

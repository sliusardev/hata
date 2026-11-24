<?php

namespace Hata\Http\Controllers;

use Hata\Support\Content\EntryPage;
use Hata\Support\Content\HomePage;
use Hata\Support\Localization\ContentLocales;
use Hata\Support\Seo\PageMeta;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * The site's address in every language: the chosen page when it is public in the language, else the neutral home.
 * The alternates stay each active language's home address.
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
        $record = $home->recordFor($this->locales->current()->code);

        if ($record !== null && $record->isPublic()) {
            return $entryPage->response($record, $request->user(), preview: false, languageOff: false, canonical: $url);
        }

        $meta
            ->setTitle((string) config('app.name'))
            ->setOgTitle((string) config('app.name'))
            ->setCanonical($url);

        $hint = null;

        if ($request->user()?->can('settings.edit')) {
            $hint = $home->id() === null ? 'choose' : 'unpublished';
        }

        return response()->view('hata::site.home', ['hint' => $hint]);
    }
}

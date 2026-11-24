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
        private readonly HomePage $home,
        private readonly ContentLocales $locales,
        private readonly EntryPage $entryPage,
        private readonly PageMeta $meta,
    ) {}

    public function __invoke(Request $request): Response
    {
        $url = $this->locales->route('home');
        $record = $this->home->recordFor($this->locales->current()->code);

        if ($record !== null && $record->isPublic()) {
            return $this->entryPage->response($record, $request->user(), preview: false, languageOff: false, canonical: $url);
        }

        $this->meta
            ->setTitle((string) config('app.name'))
            ->setOgTitle((string) config('app.name'))
            ->setCanonical($url);

        $hint = null;

        if ($request->user()?->can('settings.edit')) {
            $hint = $this->home->id() === null ? 'choose' : 'unpublished';
        }

        return response()->view('hata::site.home', ['hint' => $hint]);
    }
}

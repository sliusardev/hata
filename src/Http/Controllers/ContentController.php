<?php

namespace Hata\Http\Controllers;

use Hata\Models\Entry;
use Hata\Support\Content\ContentLookup;
use Hata\Support\Content\ContentRouter;
use Hata\Support\Content\ContentType;
use Hata\Support\Content\EntryPage;
use Hata\Support\Localization\ContentLocales;
use Hata\Support\Seo\PageMeta;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * A content record at its public address (the `content` wildcard route, tried after every other route). Plain
 * Blade, no Livewire.
 */
class ContentController extends Controller
{
    public function __construct(
        private readonly ContentRouter $router,
        private readonly ContentLocales $locales,
        private readonly EntryPage $entryPage,
        private readonly PageMeta $meta,
    ) {}

    public function __invoke(Request $request, string $path): Response|RedirectResponse
    {
        $lookup = $request->attributes->get(ContentRouter::LOOKUP_ATTRIBUTE);

        // LookUpContent found nothing (a visitor with a session goes on to here, so their miss and their hidden record
        // are answered alike, after the session).
        abort_if($request->attributes->has(ContentRouter::LOOKUP_ATTRIBUTE) && $lookup === null, 404);

        $match = $this->router->resolve(
            $this->locales->current()->code,
            $path,
            $request->user(),
            $lookup instanceof ContentLookup ? $lookup : null,
        );

        abort_if($match === null, 404);

        // In a language that is switched off only a record opens, as a preview; its lists stay 404.
        $languageOff = ! $this->locales->isActive($this->locales->current()->code);

        if ($match->listOf !== null) {
            abort_if($languageOff, 404);

            return $this->listOf($match->listOf);
        }

        if ($match->redirectTo !== null) {
            // Not kept for good: the old path may be taken by a new record later.
            return redirect($match->redirectTo, 301)->header('Cache-Control', 'no-cache');
        }

        /** @var Entry $entry */
        $entry = $match->entry;

        // In a language that is switched off every page is a preview, for those who may preview its type only.
        abort_if($languageOff && ! ($request->user()?->can('preview', $entry) ?? false), 404);

        $preview = $match->preview || $languageOff;

        $this->locales->useAlternates($this->alternates($entry));

        return $this->entryPage->response($entry, $request->user(), $preview, $languageOff);
    }

    /**
     * The list of a type's public records in the current language, newest first, a page at a time. Two queries, a
     * third for the rows' images.
     */
    private function listOf(ContentType $type): Response
    {
        $locale = $this->locales->current()->code;
        $alternates = [];

        foreach ($this->locales->active() as $language) {
            $base = trim((string) $type->base($language->code), '/');

            // A language where the type sits at the root has no list address.
            if ($base !== '') {
                $alternates[$language->code] = $this->locales->route('content', ['path' => $base], $language->code);
            }
        }

        $this->locales->useAlternates($alternates);

        $records = $this->entryPage->page(Entry::query()->ofType($type->key())->where('locale', $locale));
        $url = $this->locales->route('content', ['path' => trim((string) $type->base($locale), '/')], $locale);

        $this->meta
            ->setTitle($type->pluralLabel().' - '.config('app.name'))
            ->setOgTitle($type->pluralLabel())
            ->setCanonical(EntryPage::withPage($url, $records->currentPage()));

        return response()->view('hata::site.list', [
            'type' => $type,
            'records' => $records,
        ]);
    }

    /**
     * The addresses of the record's public translations in the active languages (the record itself included when it
     * is public), in the site's order of languages.
     *
     * @return array<string, string> code => address
     */
    private function alternates(Entry $entry): array
    {
        $byLocale = $entry->groupEntries->keyBy('locale');
        $alternates = [];

        foreach ($this->locales->active() as $language) {
            $translation = $byLocale->get($language->code);

            if ($translation instanceof Entry) {
                $alternates[$language->code] = $translation->url();
            }
        }

        return $alternates;
    }
}

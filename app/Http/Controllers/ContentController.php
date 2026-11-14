<?php

namespace App\Http\Controllers;

use App\Models\Entry;
use App\Support\Content\BodyRenderer;
use App\Support\Content\ContentLookup;
use App\Support\Content\ContentRouter;
use App\Support\Localization\ContentLocales;
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
        private readonly BodyRenderer $renderer,
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

        if ($match->redirectTo !== null) {
            // Not kept for good: the old path may be taken by a new record later.
            return redirect($match->redirectTo, 301)->header('Cache-Control', 'no-cache');
        }

        /** @var Entry $entry */
        $entry = $match->entry;

        $this->locales->useAlternates($this->alternates($entry));

        $response = response()->view('site.entry', [
            'entry' => $entry,
            'body' => $this->renderer->render($entry->body),
            'preview' => $match->preview,
            'editUrl' => $request->user()?->can('update', $entry)
                ? route('admin.content.edit', ['type' => $entry->type, 'entry' => $entry->id])
                : null,
        ]);

        return $match->preview ? $response->header('Cache-Control', 'no-store') : $response;
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

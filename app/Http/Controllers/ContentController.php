<?php

namespace App\Http\Controllers;

use App\Models\Entry;
use App\Models\Media;
use App\Support\Content\BodyRenderer;
use App\Support\Content\ContentLookup;
use App\Support\Content\ContentRouter;
use App\Support\Content\Fields\Gallery;
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

        // In a language that is switched off every page is a preview, for those who may preview its type only.
        $languageOff = ! $this->locales->isActive($this->locales->current()->code);

        abort_if($languageOff && ! ($request->user()?->can('preview', $entry) ?? false), 404);

        $preview = $match->preview || $languageOff;

        $this->locales->useAlternates($this->alternates($entry));

        $response = response()->view('site.entry', [
            'entry' => $entry,
            'body' => $this->renderer->render($entry->body),
            'galleries' => $this->galleries($entry),
            'preview' => $preview,
            'languageOff' => $languageOff,
            'editUrl' => $request->user()?->can('update', $entry)
                ? route('admin.content.edit', ['type' => $entry->type, 'entry' => $entry->id])
                : null,
        ]);

        return $preview ? $response->header('Cache-Control', 'no-store') : $response;
    }

    /**
     * The images of the record's gallery fields, by field name, in the order they were picked. One query, and only
     * for a record whose type has a gallery with something in it.
     *
     * @return array<string, list<Media>>
     */
    private function galleries(Entry $entry): array
    {
        $ids = [];

        foreach ($entry->type()->fields() as $field) {
            if ($field instanceof Gallery) {
                $ids[$field->name] = $field->mediaIds($entry->fieldValue($field->name));
            }
        }

        $all = array_values(array_unique(array_merge(...array_values($ids))));

        if ($all === []) {
            return [];
        }

        $files = Media::query()->whereKey($all)->get()->keyBy('id');
        $galleries = [];

        foreach ($ids as $name => $fieldIds) {
            $images = [];

            foreach ($fieldIds as $id) {
                $file = $files->get($id);

                if ($file instanceof Media && $file->isImage()) {
                    $images[] = $file;
                }
            }

            if ($images !== []) {
                $galleries[$name] = $images;
            }
        }

        return $galleries;
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

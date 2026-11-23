<?php

namespace Hata\Http\Controllers;

use Hata\Models\Entry;
use Hata\Models\Media;
use Hata\Support\Content\BodyRenderer;
use Hata\Support\Content\ContentLookup;
use Hata\Support\Content\ContentRouter;
use Hata\Support\Content\ContentType;
use Hata\Support\Content\ContentTypes;
use Hata\Support\Content\Fields\Gallery;
use Hata\Support\Localization\ContentLocales;
use Illuminate\Contracts\Pagination\Paginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * A content record at its public address (the `content` wildcard route, tried after every other route). Plain
 * Blade, no Livewire.
 */
class ContentController extends Controller
{
    /**
     * How many records a list page shows.
     */
    public const int PER_PAGE = 12;

    public function __construct(
        private readonly ContentTypes $types,
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

        $type = $entry->type();

        $response = response()->view($type->isTaxonomy() ? 'hata::site.term' : 'hata::site.entry', [
            'entry' => $entry,
            'records' => $type->isTaxonomy() ? $this->filedUnder($entry) : null,
            'terms' => $this->termsOf($entry),
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

        return response()->view('hata::site.list', [
            'type' => $type,
            'records' => $this->page(Entry::query()->ofType($type->key())->where('locale', $locale)),
        ]);
    }

    /**
     * The public records filed under a term, newest first, a page at a time: records of the types the taxonomy
     * classifies that are switched on, in the term's language.
     *
     * @return Paginator<int, Entry>
     */
    private function filedUnder(Entry $term): Paginator
    {
        $types = array_values(array_filter($term->type()->taxonomyOf(), fn (string $key): bool => $this->types->isActive($key)));

        // No select here: page() names the columns a card needs (a select would bring the bodies along).
        return $this->page($term->termRecords()->getQuery()->whereIn('entries.type', $types));
    }

    /**
     * One page of public records, newest first, with only what a card shows and its image. Prev/next links only
     * (no count query).
     *
     * @param  Builder<Entry>  $query
     * @return Paginator<int, Entry>
     */
    private function page(Builder $query): Paginator
    {
        return $query
            ->public()
            ->with(['image' => fn (BelongsTo $image) => $image->select(['id', 'key', 'name', 'disk', 'path', 'thumb_path', 'kind', 'mime', 'version', 'width', 'height', 'alt'])])
            ->orderByDesc('entries.published_at')
            ->orderByDesc('entries.id')
            ->simplePaginate(self::PER_PAGE, ['entries.id', 'entries.type', 'entries.locale', 'entries.title', 'entries.path', 'entries.excerpt', 'entries.image_id', 'entries.published_at'])
            ->withQueryString();
    }

    /**
     * The live terms a record is filed under, by taxonomy key, for its page. One query, only for a type with
     * taxonomies that are on.
     *
     * @return array<string, list<Entry>>
     */
    private function termsOf(Entry $entry): array
    {
        $taxonomies = array_filter($this->types->taxonomiesOf($entry->type), fn (ContentType $taxonomy): bool => $this->types->isActive($taxonomy->key()));

        if ($taxonomies === []) {
            return [];
        }

        $terms = [];

        foreach ($entry->terms()->whereIn('entries.type', array_keys($taxonomies))->orderBy('entries.title')->get(['entries.id', 'entries.type', 'entries.locale', 'entries.title', 'entries.path']) as $term) {
            $terms[$term->type][] = $term;
        }

        return $terms;
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

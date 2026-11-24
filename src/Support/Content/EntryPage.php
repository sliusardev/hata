<?php

namespace Hata\Support\Content;

use Hata\Contracts\AdminUser;
use Hata\Models\Entry;
use Hata\Models\Media;
use Hata\Support\Content\Fields\Gallery;
use Hata\Support\Seo\PageMeta;
use Illuminate\Contracts\Pagination\Paginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Http\Response;

/**
 * The public page of one record or term: fills the head's `PageMeta` and renders `site.entry` or `site.term` with
 * what they print. Used by `ContentController` and by the home page.
 */
class EntryPage
{
    /**
     * How many records a list page shows.
     */
    public const int PER_PAGE = 12;

    public function __construct(
        private readonly ContentTypes $types,
        private readonly BodyRenderer $renderer,
    ) {}

    /**
     * The page of a record, a preview (no index, never cached) when `$preview`. `$canonical` replaces the record's
     * own address (the home page's). The alternates are whatever `ContentLocales` holds: the caller sets them first
     * when they differ from the default.
     */
    public function response(Entry $entry, ?AdminUser $viewer, bool $preview, bool $languageOff, ?string $canonical = null): Response
    {
        $type = $entry->type();
        $taxonomy = $type->isTaxonomy();
        $records = $taxonomy ? $this->filedUnder($entry) : null;

        // resolved here, not held: PageMeta is scoped to the request and this object may outlive it
        app(PageMeta::class)
            ->setTitle($entry->documentTitle())
            ->setOgTitle(filled($entry->seo_title) ? (string) $entry->seo_title : $entry->title)
            ->setDescription(filled($entry->seo_description) ? $entry->seo_description : (filled($entry->excerpt) ? $entry->excerpt : $entry->body))
            ->setCanonical(self::withPage($canonical ?? $entry->url(), $records?->currentPage() ?? 1))
            ->setImage($taxonomy ? null : $entry->image)
            ->setType($taxonomy || $type->hierarchical() ? 'website' : 'article')
            ->setNoindex($preview);

        $response = response()->view($taxonomy ? 'hata::site.term' : 'hata::site.entry', [
            'entry' => $entry,
            'records' => $records,
            'terms' => $this->termsOf($entry),
            'body' => $this->renderer->render($entry->body),
            'galleries' => $this->galleries($entry),
            'preview' => $preview,
            'languageOff' => $languageOff,
            'editUrl' => $viewer?->can('update', $entry)
                ? route('admin.content.edit', ['type' => $entry->type, 'entry' => $entry->id])
                : null,
        ]);

        return $preview ? $response->header('Cache-Control', 'no-store') : $response;
    }

    /**
     * An address with `?page=N` for a page after the first (the only query value a canonical keeps).
     */
    public static function withPage(string $url, int $page): string
    {
        return $page > 1 ? $url.'?page='.$page : $url;
    }

    /**
     * One page of public records, newest first, with only what a card shows and its image. Prev/next links only
     * (no count query).
     *
     * @param  Builder<Entry>  $query
     * @return Paginator<int, Entry>
     */
    public function page(Builder $query): Paginator
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
}

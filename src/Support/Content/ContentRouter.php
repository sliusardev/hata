<?php

namespace Hata\Support\Content;

use App\Models\User;
use Hata\Models\Entry;
use Hata\Models\EntryRedirect;
use Hata\Support\Localization\ContentLocales;
use Hata\Support\Localization\Language;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Gate;

/**
 * Finds the record a public address shows, in the request's language.
 *
 * The bases come from code (the registered types), so the route is a single cacheable wildcard. A first segment
 * equal to a type's base in that language means a record of that type; anything else is a page by its full path.
 * On a miss the old paths in `entry_redirects` are looked up. The lookup needs no viewer and runs before the session
 * (LookUpContent); a record that is not public is then shown only to a viewer who may edit its type (resolve()).
 */
class ContentRouter
{
    /**
     * What a content address can look like: lowercase slugs joined by `/`. The route carries it, so any other
     * address (`/favicon.ico`, `/.env`, `/About`) is the router's own 404, before any middleware or query.
     */
    public const string PATH_PATTERN = '[a-z0-9]+(?:-[a-z0-9]+)*(?:/[a-z0-9]+(?:-[a-z0-9]+)*)*';

    public function __construct(
        private readonly ContentTypes $types,
        private readonly ContentLocales $locales,
    ) {}

    /**
     * The request attribute in which LookUpContent hands its ContentLookup to the controller, so a hit is not
     * looked up twice.
     */
    public const string LOOKUP_ATTRIBUTE = 'content.lookup';

    /**
     * What the address holds, whoever asks: the record at the path in any status (trashed ones left out), else the
     * live record an old path points to. Needs no session or user, so it runs before the session starts
     * (LookUpContent) and a miss costs nothing more. One query for a record, two for a redirect or a miss; none for
     * an address that cannot be content and for a type's bare base (its list).
     */
    public function lookup(string $locale, string $path): ?ContentLookup
    {
        // The route already holds to this; checked again for direct calls, and because MySQL compares paths
        // without regard to case. `\z`, not `$`, which would accept a trailing newline.
        if (preg_match('#^'.self::PATH_PATTERN.'\z#', $path) !== 1) {
            return null;
        }

        [$types, $recordPath] = $this->candidates($locale, $path);

        if ($types === []) {
            return null;
        }

        if ($recordPath === '') {
            // A bare base is the list of the type's records; a taxonomy has none (its terms are the lists).
            $type = $this->types->get($types[0]);

            return $type->isTaxonomy() ? null : new ContentLookup(null, redirect: false, listOf: $type);
        }

        $entry = $this->first($types, Entry::query()->where('locale', $locale)->where('path', $recordPath));

        if ($entry !== null) {
            return new ContentLookup($entry, redirect: false);
        }

        $target = $this->first($types, Entry::query()->whereIn('id', EntryRedirect::query()
            ->select('entry_id')
            ->whereIn('type', $types)
            ->where('locale', $locale)
            ->where('path', $recordPath)));

        return $target === null ? null : new ContentLookup($target, redirect: true);
    }

    /**
     * What the viewer gets at the address: the record (a preview when it is not public and the viewer may edit its
     * type), the address a redirect leads to (only to a record the viewer may see), or null. Takes the lookup
     * LookUpContent already made, else makes it. Then one query for the record's translations (the public ones, or
     * all of them for a viewer who may edit the type; none on a
     * one-language site) and one for its image when it has one.
     */
    public function resolve(string $locale, string $path, ?User $viewer, ?ContentLookup $lookup = null): ?ContentMatch
    {
        $lookup ??= $this->lookup($locale, $path);

        if ($lookup === null) {
            return null;
        }

        if ($lookup->listOf !== null || $lookup->entry === null) {
            return $lookup->listOf !== null ? ContentMatch::list($lookup->listOf) : null;
        }

        $entry = $lookup->entry;
        $preview = ! $entry->isPublic();

        if ($preview && ! $this->mayPreview($entry, $viewer)) {
            return null;
        }

        if ($lookup->redirect) {
            return ContentMatch::redirect($entry->url());
        }

        $activeLocales = $this->locales->activeCodes();

        if (count($activeLocales) === 1) {
            // One language: no hreflang and no switcher, so no translations to look up.
            $entry->setRelation('groupEntries', new Collection);
        } else {
            // A viewer who may edit the type gets the drafts and scheduled translations too (they open as previews),
            // so the switcher leads an editor from one language of a page to the other before it is published.
            $withHidden = $this->mayPreview($entry, $viewer);

            $entry->load(['groupEntries' => fn (HasMany $query) => $query
                ->select(['id', 'entry_group_id', 'type', 'locale', 'path'])
                ->when(! $withHidden, fn ($query) => $query->public())
                ->whereIn('locale', $activeLocales),
            ]);
        }

        if ($entry->image_id !== null) {
            $entry->load('image');
        }

        return ContentMatch::entry($entry, $preview);
    }

    /**
     * The types an address may belong to and the record's path within them: the type whose base in this language
     * is the first segment (a bare base leaves an empty path: the type's list, built later), else every type that
     * sits at the root with the full path.
     *
     * @return array{0: list<string>, 1: string}
     */
    private function candidates(string $locale, string $path): array
    {
        $segments = explode('/', $path, 2);
        $atRoot = [];

        // Only the types that are switched on: a type that is off has no public addresses.
        foreach ($this->types->active() as $type) {
            $base = trim((string) $type->base($locale), '/');

            if ($base === '') {
                $atRoot[] = $type->key();
            } elseif ($base === $segments[0]) {
                return [[$type->key()], $segments[1] ?? ''];
            }
        }

        return [$atRoot, $path];
    }

    /**
     * The record of the earliest registered type among those that match: types at the root share the address
     * space, and each may hold the path once.
     *
     * @param  list<string>  $types
     * @param  Builder<Entry>  $query
     */
    private function first(array $types, Builder $query): ?Entry
    {
        /** @var Collection<int, Entry> $found */
        $found = $query->whereIn('type', $types)->get();

        return $found->sortBy(fn (Entry $entry): int|false => array_search($entry->type, $types, true))->first();
    }

    private function mayPreview(Entry $entry, ?User $viewer): bool
    {
        return $viewer !== null && Gate::forUser($viewer)->allows('preview', $entry);
    }
}

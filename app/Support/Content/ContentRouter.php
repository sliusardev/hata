<?php

namespace App\Support\Content;

use App\Models\Entry;
use App\Models\EntryRedirect;
use App\Models\User;
use App\Support\Localization\ContentLocales;
use App\Support\Localization\Language;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Gate;

/**
 * Finds the record a public address shows, in the request's language.
 *
 * The bases come from code (the registered types), so the route is a single cacheable wildcard. A first segment
 * equal to a type's base in that language means a record of that type; anything else is a page by its full path.
 * On a miss the old paths in `entry_redirects` are looked up. A record that is not public is found only for a
 * viewer who may edit its type.
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
     * One query for a record that is found, then one for its public translations (none on a one-language site) and
     * one for its image when it has one; a miss costs one more query, for the redirects.
     */
    public function resolve(string $locale, string $path, ?User $viewer): ?ContentMatch
    {
        // The route already holds to this; checked again for direct calls, and because MySQL compares paths
        // without regard to case.
        if (preg_match('#^'.self::PATH_PATTERN.'$#', $path) !== 1) {
            return null;
        }

        [$types, $recordPath] = $this->candidates($locale, $path);

        if ($types === [] || $recordPath === '') {
            return null;
        }

        $entry = $this->first($types, Entry::query()->where('locale', $locale)->where('path', $recordPath));

        if ($entry === null) {
            return $this->redirect($types, $locale, $recordPath, $viewer);
        }

        $preview = ! $entry->isPublic();

        if ($preview && ! $this->mayPreview($entry, $viewer)) {
            return null;
        }

        $activeLocales = array_map(fn (Language $language): string => $language->code, $this->locales->active());

        if (count($activeLocales) === 1) {
            // One language: no hreflang and no switcher, so no translations to look up.
            $entry->setRelation('groupEntries', new Collection);
        } else {
            $entry->load(['groupEntries' => fn (HasMany $query) => $query
                ->select(['id', 'entry_group_id', 'type', 'locale', 'path'])
                ->public()
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

        foreach ($this->types->all() as $type) {
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
     * @param  list<string>  $types
     */
    private function redirect(array $types, string $locale, string $path, ?User $viewer): ?ContentMatch
    {
        $target = $this->first($types, Entry::query()->whereIn('id', EntryRedirect::query()
            ->select('entry_id')
            ->whereIn('type', $types)
            ->where('locale', $locale)
            ->where('path', $path)));

        if ($target === null || (! $target->isPublic() && ! $this->mayPreview($target, $viewer))) {
            return null;
        }

        return ContentMatch::redirect($target->url());
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

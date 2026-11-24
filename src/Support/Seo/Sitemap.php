<?php

namespace Hata\Support\Seo;

use Carbon\CarbonImmutable;
use Generator;
use Hata\Models\Entry;
use Hata\Support\Content\ContentType;
use Hata\Support\Content\ContentTypes;
use Hata\Support\Content\HomePage;
use Hata\Support\Content\Types\PagesType;
use Hata\Support\Localization\ContentLocales;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * The content of `/sitemap.xml`: every public address of every active language, built on request. Resolve it per
 * request (it holds the request-scoped `HomePage`).
 */
class Sitemap
{
    public function __construct(
        private readonly ContentLocales $locales,
        private readonly ContentTypes $types,
        private readonly HomePage $home,
    ) {}

    /**
     * The settings whose change changes the sitemap. Others are left out: the media probe rewrites its stored-only
     * settings every 30 minutes to a day.
     */
    public const array SETTINGS = ['home_page', 'content_locales', 'content_default_locale', 'content_disabled_types'];

    /**
     * A stored-only setting `SiteSettings::put()` rewrites whenever one of SETTINGS is stored or removed: a removed
     * row leaves no `updated_at` to read.
     */
    public const string CHANGED_SETTING = 'sitemap_changed_at';

    /**
     * When the sitemap last changed, by one query: the latest of every record's `updated_at` (trashed ones too: a
     * trash sets it), the latest `published_at` that has come (a scheduled record goes live without a write), the
     * groups' `updated_at` (linking and unlinking translations touch them, not the records) and the `updated_at` of
     * the settings in SETTINGS and of the marker CHANGED_SETTING (which also moves when one of them is removed). Null
     * on a site with none of them.
     */
    public function lastModified(): ?CarbonImmutable
    {
        $row = DB::query()
            ->selectSub(DB::table('entries')->selectRaw('max(updated_at)'), 'entry_updated')
            ->selectSub(DB::table('entries')->selectRaw('max(published_at)')->where('published_at', '<=', now()), 'entry_published')
            ->selectSub(DB::table('entry_groups')->selectRaw('max(updated_at)'), 'group_updated')
            ->selectSub(DB::table('settings')->selectRaw('max(updated_at)')->whereIn('key', [...self::SETTINGS, self::CHANGED_SETTING]), 'setting_updated')
            ->first();

        $latest = null;

        foreach ((array) $row as $value) {
            if ($value === null) {
                continue;
            }

            $moment = CarbonImmutable::parse((string) $value, 'UTC');
            $latest = $latest === null || $moment->gt($latest) ? $moment : $latest;
        }

        return $latest;
    }

    /**
     * The XML, chunk by chunk.
     *
     * @return Generator<int, string>
     */
    public function lines(): Generator
    {
        yield '<?xml version="1.0" encoding="UTF-8"?>'."\n";
        yield '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" xmlns:xhtml="http://www.w3.org/1999/xhtml">'."\n";

        $active = $this->locales->activeCodes();
        $activeTypes = array_filter($this->types->active(), fn (ContentType $type): bool => ! $type->isTaxonomy());
        $newest = $this->newestPerType($activeTypes, $active);

        yield from $this->homes($newest);
        yield from $this->records($activeTypes, $active);
        yield from $this->lists($activeTypes, $newest);
        yield from $this->terms($activeTypes, $active);

        yield '</urlset>'."\n";
    }

    /**
     * Every active language's home, with the others as alternates; none while no page is chosen (the Hata
     * introduction is not indexed).
     *
     * @param  array<string, array<string, CarbonImmutable>>  $newest  type => locale => latest change
     * @return Generator<int, string>
     */
    private function homes(array $newest): Generator
    {
        if ($this->home->id() === null) {
            return;
        }

        $alternates = [];

        foreach ($this->locales->active() as $language) {
            $alternates[$language->hreflang()] = $this->locales->route('home', [], $language->code);
        }

        foreach ($this->locales->active() as $language) {
            $record = $this->home->recordFor($language->code);
            $latest = $record?->isPublic() ? $record->updated_at : $this->latestIn($newest, $language->code);

            yield $this->url($this->locales->route('home', [], $language->code), $latest, count($alternates) > 1 ? $alternates : []);
        }
    }

    /**
     * The public records of the active types, except the chosen home page's, a group's translations together.
     *
     * @param  array<string, ContentType>  $types
     * @param  list<string>  $locales
     * @return Generator<int, string>
     */
    private function records(array $types, array $locales): Generator
    {
        $homeGroup = $this->home->groupId();

        $query = Entry::query()
            ->public()
            ->whereIn('type', array_keys($types))
            ->whereIn('locale', $locales)
            ->when($homeGroup !== null, fn (Builder $query) => $query->where(
                fn (Builder $other) => $other->where('type', '<>', PagesType::KEY)->orWhere('entry_group_id', '<>', $homeGroup),
            ))
            ->select(['id', 'entry_group_id', 'type', 'locale', 'path', 'updated_at']);

        yield from $this->grouped($query);
    }

    /**
     * The list of a type with a base, when it has a public record in the language.
     *
     * @param  array<string, ContentType>  $types
     * @param  array<string, array<string, CarbonImmutable>>  $newest
     * @return Generator<int, string>
     */
    private function lists(array $types, array $newest): Generator
    {
        foreach ($types as $key => $type) {
            foreach ($newest[$key] ?? [] as $locale => $latest) {
                $base = trim((string) $type->base($locale), '/');

                if ($base === '') {
                    continue;
                }

                yield $this->url($this->locales->route('content', ['path' => $base], $locale), $latest, []);
            }
        }
    }

    /**
     * The terms of the active taxonomies that have a public record of an active type filed under them.
     *
     * @param  array<string, ContentType>  $types
     * @param  list<string>  $locales
     * @return Generator<int, string>
     */
    private function terms(array $types, array $locales): Generator
    {
        $taxonomies = array_keys(array_filter($this->types->active(), fn (ContentType $type): bool => $type->isTaxonomy()));

        if ($taxonomies === []) {
            return;
        }

        $query = Entry::query()
            ->whereIn('type', $taxonomies)
            ->whereIn('locale', $locales)
            ->whereHas('termRecords', fn (Builder $records) => $records->public()->whereIn('type', array_keys($types)))
            ->select(['id', 'entry_group_id', 'type', 'locale', 'path', 'updated_at']);

        yield from $this->grouped($query);
    }

    /**
     * Rows read ordered by group and language, a group's rows buffered and written together with each other as
     * alternates.
     *
     * @param  Builder<Entry>  $query
     * @return Generator<int, string>
     */
    private function grouped(Builder $query): Generator
    {
        $group = null;
        $buffer = [];

        foreach ($query->orderBy('entry_group_id')->orderBy('locale')->cursor() as $entry) {
            if ($group !== null && $entry->entry_group_id !== $group) {
                yield from $this->flush($buffer);
                $buffer = [];
            }

            $group = $entry->entry_group_id;
            $buffer[] = $entry;
        }

        yield from $this->flush($buffer);
    }

    /**
     * @param  list<Entry>  $group
     * @return Generator<int, string>
     */
    private function flush(array $group): Generator
    {
        $alternates = [];

        if (count($group) > 1) {
            foreach ($group as $entry) {
                $alternates[$this->locales->language($entry->locale)->hreflang()] = $entry->url();
            }
        }

        foreach ($group as $entry) {
            yield $this->url($entry->url(), $entry->updated_at, $alternates);
        }
    }

    /**
     * The latest `updated_at` or `published_at` of the public records, per type and language, in one query.
     *
     * @param  array<string, ContentType>  $types
     * @param  list<string>  $locales
     * @return array<string, array<string, CarbonImmutable>> type => locale => moment
     */
    private function newestPerType(array $types, array $locales): array
    {
        $rows = Entry::query()
            ->public()
            ->whereIn('type', array_keys($types))
            ->whereIn('locale', $locales)
            ->selectRaw('type, locale, max(updated_at) as updated, max(published_at) as published')
            ->groupBy('type', 'locale')
            ->toBase()
            ->get();

        $newest = [];

        foreach ($rows as $row) {
            $moments = array_map(fn (string $moment): CarbonImmutable => CarbonImmutable::parse($moment, 'UTC'), array_filter([$row->updated, $row->published]));
            $newest[(string) $row->type][(string) $row->locale] = collect($moments)->sortDesc()->first();
        }

        return $newest;
    }

    /**
     * @param  array<string, array<string, CarbonImmutable>>  $newest
     */
    private function latestIn(array $newest, string $locale): ?CarbonImmutable
    {
        $moments = array_column($newest, $locale);

        return $moments === [] ? null : collect($moments)->sortDesc()->first();
    }

    /**
     * @param  array<string, string>  $alternates  hreflang => address
     */
    private function url(string $address, ?\DateTimeInterface $modified, array $alternates): string
    {
        $xml = '<url><loc>'.$this->escape($address).'</loc>';

        if ($modified !== null) {
            $xml .= '<lastmod>'.CarbonImmutable::instance($modified)->utc()->toAtomString().'</lastmod>';
        }

        foreach ($alternates as $hreflang => $href) {
            $xml .= '<xhtml:link rel="alternate" hreflang="'.$this->escape((string) $hreflang).'" href="'.$this->escape($href).'"/>';
        }

        return $xml.'</url>'."\n";
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
}

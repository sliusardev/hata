<?php

namespace App\Support\Content;

use App\Enums\EntryState;
use App\Enums\EntryStatus;
use App\Models\Entry;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Query\Builder as QueryBuilder;

/**
 * The records of one type as the admin list shows them, in one language or in all the site's: a tab, a search and a sort. The query count does not depend on the number of rows: the image, the author and the group's
 * records are eager loaded with only the columns a row shows.
 */
final class EntryListQuery
{
    public const array TABS = ['all', 'published', 'drafts', 'scheduled', 'trash'];

    public const array SORTS = ['title', 'date', 'tree'];

    /**
     * The columns a row needs: no body, excerpt or SEO texts.
     */
    private const array COLUMNS = [
        'id', 'entry_group_id', 'type', 'locale', 'parent_id', 'author_id', 'image_id', 'title', 'slug', 'path',
        'status', 'published_at', 'created_at', 'deleted_at',
    ];

    private string $tab = 'all';

    private ?string $search = null;

    private string $sort;

    /**
     * @var 'asc'|'desc'
     */
    private string $direction;

    /**
     * @param  list<string>  $locales
     */
    private function __construct(private readonly ContentType $type, private readonly string $locale, private readonly array $locales)
    {
        $this->sort = $type->hierarchical() ? 'tree' : 'date';
        $this->direction = $type->hierarchical() ? 'asc' : 'desc';
    }

    /**
     * An empty language means every language in `$locales` (the site's, in their order).
     *
     * @param  list<string>  $locales
     */
    public static function for(ContentType $type, string $locale, array $locales = []): self
    {
        return new self($type, $locale, $locales);
    }

    /**
     * @param  Builder<Entry>  $query
     * @return Builder<Entry>
     */
    private function inLanguages(Builder $query): Builder
    {
        return $this->locale !== '' ? $query->where('locale', $this->locale) : $query->whereIn('locale', $this->locales);
    }

    /**
     * An unknown tab is `all`.
     */
    public function tab(string $tab): self
    {
        $this->tab = in_array($tab, self::TABS, true) ? $tab : 'all';

        return $this;
    }

    public function search(?string $term): self
    {
        $this->search = $term;

        return $this;
    }

    /**
     * `tree` is path order and only applies to a hierarchical type; an unknown sort keeps the type's default.
     */
    public function sort(string $column, string $direction): self
    {
        if (in_array($column, self::SORTS, true) && ($column !== 'tree' || $this->type->hierarchical())) {
            $this->sort = $column;
        }

        $this->direction = $direction === 'desc' ? 'desc' : 'asc';

        return $this;
    }

    /**
     * Whether rows come in tree order, so the list may indent them by depth: a hierarchical type sorted as a tree,
     * outside the trash (which is a flat list by trashing time).
     */
    public function isTree(): bool
    {
        return $this->sort === 'tree' && $this->tab !== 'trash';
    }

    /**
     * @return Builder<Entry>
     */
    public function query(): Builder
    {
        $query = Entry::query()
            ->select(self::COLUMNS)
            ->ofType($this->type->key())
            ->with([
                'image' => fn (BelongsTo $image) => $image->select(['id', 'key', 'name', 'disk', 'thumb_path', 'kind', 'mime', 'version']),
                'author' => fn (BelongsTo $author) => $author->select(['id', 'name']),
                'groupEntries' => fn (HasMany $entries) => $entries->select(['id', 'entry_group_id', 'locale', 'title', 'status', 'published_at']),
            ])
            ->search($this->search);

        $this->inLanguages($query);

        if ($this->isGrouped()) {
            $this->onePerGroup($query);
        }

        match ($this->tab) {
            'published' => $query->inState(EntryState::Published),
            'drafts' => $query->inState(EntryState::Draft),
            'scheduled' => $query->inState(EntryState::Scheduled),
            'trash' => $query->onlyTrashed(),
            default => null,
        };

        return $this->order($query);
    }

    /**
     * Whether the list shows one row per group of translations: every language listed, outside the trash (where
     * each record is restored or deleted on its own).
     */
    public function isGrouped(): bool
    {
        return $this->locale === '' && $this->tab !== 'trash' && count($this->locales) > 1;
    }

    /**
     * Keeps, of every group, the record in the first of the site's languages among those the tab and the search
     * match: no live record of the group in an earlier language matches them too. A correlated `not exists` on the
     * (`entry_group_id`, `locale`) index, a few rows per listed record.
     *
     * @param  Builder<Entry>  $query
     */
    private function onePerGroup(Builder $query): void
    {
        $query->whereNotExists(function (QueryBuilder $earlier): void {
            $earlier->selectRaw('1')
                ->from('entries as earlier')
                ->whereColumn('earlier.entry_group_id', 'entries.entry_group_id')
                ->whereNull('earlier.deleted_at')
                ->where(function (QueryBuilder $ranks): void {
                    foreach ($this->locales as $position => $code) {
                        if ($position > 0) {
                            $ranks->orWhere(fn (QueryBuilder $rank) => $rank
                                ->where('entries.locale', $code)
                                ->whereIn('earlier.locale', array_slice($this->locales, 0, $position)));
                        }
                    }
                });

            $now = now();
            $published = EntryStatus::Published->value;

            match ($this->tab) {
                'published' => $earlier->where('earlier.status', $published)->where('earlier.published_at', '<=', $now),
                'drafts' => $earlier->where(fn (QueryBuilder $drafts) => $drafts->where('earlier.status', '<>', $published)->orWhereNull('earlier.published_at')),
                'scheduled' => $earlier->where('earlier.status', $published)->where('earlier.published_at', '>', $now),
                default => null,
            };

            $term = mb_strtolower(trim((string) $this->search));

            if ($term !== '') {
                $earlier->where('earlier.search_index', 'like', '%'.$term.'%');
            }
        });
    }

    /**
     * @return LengthAwarePaginator<int, Entry>
     */
    public function paginate(int $perPage): LengthAwarePaginator
    {
        return $this->query()->paginate($perPage);
    }

    /**
     * How many rows each tab holds, whatever the search and filter: records of the type in the list's languages, or
     * groups of translations with a record in that state while every language is listed (the trash always counts
     * records). One query.
     *
     * @return array{all: int, published: int, drafts: int, scheduled: int, trash: int}
     */
    public function counts(): array
    {
        $now = (new Entry)->fromDateTime(now());
        $published = EntryStatus::Published->value;

        $base = $this->inLanguages(Entry::withTrashed()->ofType($this->type->key()))->toBase();

        // Rows are groups while every language is listed: a group counts once per tab it has a record in.
        $row = $this->locale === '' && count($this->locales) > 1
            ? $base
                ->selectRaw('count(distinct case when deleted_at is null then entry_group_id end) as all_count')
                ->selectRaw('count(distinct case when deleted_at is null and status = ? and published_at <= ? then entry_group_id end) as published_count', [$published, $now])
                ->selectRaw('count(distinct case when deleted_at is null and (status <> ? or published_at is null) then entry_group_id end) as drafts_count', [$published])
                ->selectRaw('count(distinct case when deleted_at is null and status = ? and published_at > ? then entry_group_id end) as scheduled_count', [$published, $now])
                ->selectRaw('sum(case when deleted_at is not null then 1 else 0 end) as trash_count')
                ->first()
            : $base
                ->selectRaw('sum(case when deleted_at is null then 1 else 0 end) as all_count')
                ->selectRaw('sum(case when deleted_at is null and status = ? and published_at <= ? then 1 else 0 end) as published_count', [$published, $now])
                ->selectRaw('sum(case when deleted_at is null and (status <> ? or published_at is null) then 1 else 0 end) as drafts_count', [$published])
                ->selectRaw('sum(case when deleted_at is null and status = ? and published_at > ? then 1 else 0 end) as scheduled_count', [$published, $now])
                ->selectRaw('sum(case when deleted_at is not null then 1 else 0 end) as trash_count')
                ->first();

        return [
            'all' => (int) ($row->all_count ?? 0),
            'published' => (int) ($row->published_count ?? 0),
            'drafts' => (int) ($row->drafts_count ?? 0),
            'scheduled' => (int) ($row->scheduled_count ?? 0),
            'trash' => (int) ($row->trash_count ?? 0),
        ];
    }

    /**
     * @param  Builder<Entry>  $query
     * @return Builder<Entry>
     */
    private function order(Builder $query): Builder
    {
        if ($this->tab === 'trash') {
            return $query->orderByDesc('deleted_at')->orderByDesc('id');
        }

        return match ($this->sort) {
            'title' => $query->orderBy('title', $this->direction)->orderBy('id', $this->direction),
            'date' => $query
                ->orderByRaw($this->direction === 'asc' ? 'coalesce(published_at, created_at) asc' : 'coalesce(published_at, created_at) desc')
                ->orderBy('id', $this->direction),
            // A slug never holds a space, and a space sorts before `-` and every letter and digit, so this keeps
            // `about/team` right after `about` and before `about-us` (plain path order would not).
            default => $this->byLanguage($query)->orderByRaw("replace(path, '/', ' ') asc")->orderBy('id'),
        };
    }

    /**
     * With every language listed, the tree of each language in the site's order of languages.
     *
     * @param  Builder<Entry>  $query
     * @return Builder<Entry>
     */
    private function byLanguage(Builder $query): Builder
    {
        if ($this->locale !== '' || count($this->locales) < 2) {
            return $query;
        }

        foreach ($this->locales as $code) {
            $query->orderByRaw('case when locale = ? then 0 else 1 end', [$code]);
        }

        return $query;
    }
}

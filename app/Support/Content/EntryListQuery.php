<?php

namespace App\Support\Content;

use App\Enums\EntryState;
use App\Models\Entry;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;

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
     * @return LengthAwarePaginator<int, Entry>
     */
    public function paginate(int $perPage): LengthAwarePaginator
    {
        return $this->query()->paginate($perPage);
    }

    /**
     * How many records of the type in the list's languages each tab holds, whatever the search: one query
     * of five scalar subqueries.
     *
     * @return array{all: int, published: int, drafts: int, scheduled: int, trash: int}
     */
    public function counts(): array
    {
        // Each tab's own query as a scalar subquery, so a state is defined once (Entry::scopeInState()) for the rows
        // and for their count, and each count reads the (type, locale, deleted_at, status, published_at) index.
        $base = fn (): Builder => $this->inLanguages(Entry::query()->ofType($this->type->key()));
        $count = fn (Builder $query): QueryBuilder => $query->toBase()->selectRaw('count(*)');

        $row = DB::query()
            ->selectSub($count($base()), 'all_count')
            ->selectSub($count($base()->inState(EntryState::Published)), 'published_count')
            ->selectSub($count($base()->inState(EntryState::Draft)), 'drafts_count')
            ->selectSub($count($base()->inState(EntryState::Scheduled)), 'scheduled_count')
            ->selectSub($count($base()->onlyTrashed()), 'trash_count')
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

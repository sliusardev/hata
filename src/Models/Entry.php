<?php

namespace Hata\Models;

use Hata\Contracts\AdminUser;
use Hata\Contracts\UsesMedia;
use Hata\Database\Factories\EntryFactory;
use Hata\Enums\EntryState;
use Hata\Enums\EntryStatus;
use Hata\Hata;
use Hata\Models\Concerns\HasSearchIndex;
use Hata\Support\Content\BodySanitizer;
use Hata\Support\Content\ContentType;
use Hata\Support\Content\ContentTypes;
use Hata\Support\Localization\ContentLocales;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;

/**
 * One content record in one language (a page, later a post). Soft deletes are the trash.
 *
 * @property int $id
 * @property int $entry_group_id
 * @property string $type
 * @property string $locale
 * @property int|null $parent_id
 * @property int|null $author_id
 * @property string $title
 * @property string $slug
 * @property string $path
 * @property string|null $body
 * @property string|null $excerpt
 * @property string|null $seo_title
 * @property string|null $seo_description
 * @property int|null $image_id
 * @property array<string, mixed>|null $fields
 * @property EntryStatus $status
 * @property Carbon|null $published_at
 * @property string|null $search_index
 * @property Carbon|null $deleted_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read EntryGroup $group
 * @property-read Entry|null $parent
 * @property-read Collection<int, Entry> $children
 * @property-read Collection<int, Entry> $groupEntries
 */
#[Fillable([
    'entry_group_id', 'type', 'locale', 'parent_id', 'author_id', 'title', 'slug', 'path', 'body', 'excerpt',
    'seo_title', 'seo_description', 'image_id', 'fields', 'status', 'published_at',
])]
#[UseFactory(EntryFactory::class)]
class Entry extends Model implements UsesMedia
{
    /** @use HasFactory<EntryFactory> */
    use HasFactory, HasSearchIndex, SoftDeletes;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'fields' => 'array',
            'status' => EntryStatus::class,
            'published_at' => 'datetime',
        ];
    }

    protected function searchIndexSource(): string
    {
        return $this->title.' '.$this->slug;
    }

    /**
     * @return BelongsTo<EntryGroup, $this>
     */
    public function group(): BelongsTo
    {
        return $this->belongsTo(EntryGroup::class, 'entry_group_id');
    }

    /**
     * @return BelongsTo<Entry, $this>
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    /**
     * @return HasMany<Entry, $this>
     */
    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    /**
     * @return BelongsTo<Authenticatable&AdminUser, $this>
     */
    public function author(): BelongsTo
    {
        return $this->belongsTo(Hata::userModel(), 'author_id');
    }

    /**
     * @return BelongsTo<Media, $this>
     */
    public function image(): BelongsTo
    {
        return $this->belongsTo(Media::class, 'image_id');
    }

    /**
     * Every record of the group, this one included (trashed ones excluded). Safe to eager load.
     *
     * @return HasMany<Entry, $this>
     */
    public function groupEntries(): HasMany
    {
        return $this->hasMany(self::class, 'entry_group_id', 'entry_group_id');
    }

    /**
     * The other records of the group. Uses the loaded `groupEntries` when there is one, so a list that
     * eager loads it costs no query per record.
     *
     * @return Collection<int, Entry>
     */
    public function translations(): Collection
    {
        return $this->groupEntries
            ->reject(fn (Entry $entry): bool => $entry->getKey() === $this->getKey())
            ->values();
    }

    /**
     * @param  Builder<static>  $query
     */
    /**
     * The terms the record is filed under: live records of the taxonomies of its type, in its language.
     *
     * @return BelongsToMany<Entry, $this>
     */
    public function terms(): BelongsToMany
    {
        return $this->belongsToMany(self::class, 'entry_terms', 'entry_id', 'term_id');
    }

    /**
     * For a term: the records filed under it.
     *
     * @return BelongsToMany<Entry, $this>
     */
    public function termRecords(): BelongsToMany
    {
        return $this->belongsToMany(self::class, 'entry_terms', 'term_id', 'entry_id');
    }

    /**
     * The ids of the translations, in a language, of the terms the record is filed under: what a translation of
     * the record, or the record moved to that language, is filed under. Two queries, none without terms.
     *
     * @return list<int>
     */
    public function termIdsIn(string $locale): array
    {
        return self::translationIds($this->terms()->pluck('entries.id')->all(), $locale);
    }

    /**
     * The ids of the live translations, in a language, of the given records. Two queries, none for no ids.
     *
     * @param  array<array-key, int|string>  $ids
     * @return list<int>
     */
    public static function translationIds(array $ids, string $locale): array
    {
        if ($ids === []) {
            return [];
        }

        $groups = self::query()->whereKey($ids)->pluck('entry_group_id');

        return $groups->isEmpty() ? [] : array_values(self::query()->where('locale', $locale)->whereIn('entry_group_id', $groups)->pluck('id')->all());
    }

    /**
     * @param  Builder<static>  $query
     */
    public function scopeOfType(Builder $query, string $type): void
    {
        $query->where('type', $type);
    }

    /**
     * @param  Builder<static>  $query
     */
    public function scopeInLocale(Builder $query, string $locale): void
    {
        $query->where('locale', $locale);
    }

    /**
     * Published, with a date that has come, and not trashed.
     *
     * @param  Builder<static>  $query
     */
    public function scopePublic(Builder $query): void
    {
        $query->inState(EntryState::Published);
    }

    /**
     * The records `state()` gives that state: the one definition of draft, scheduled and published in SQL.
     *
     * @param  Builder<static>  $query
     */
    public function scopeInState(Builder $query, EntryState $state): void
    {
        match ($state) {
            EntryState::Published => $query->where('status', EntryStatus::Published->value)->where('published_at', '<=', now()),
            EntryState::Scheduled => $query->where('status', EntryStatus::Published->value)->where('published_at', '>', now()),
            EntryState::Draft => $query->where(fn (Builder $drafts) => $drafts
                ->where('status', '<>', EntryStatus::Published->value)
                ->orWhereNull('published_at')),
        };
    }

    /**
     * The records under a path: same type and language, path starting with it and `/`. The one definition of a
     * subtree by path.
     *
     * @param  Builder<static>  $query
     */
    public function scopeUnder(Builder $query, string $type, string $locale, string $path): void
    {
        $query->where('type', $type)->where('locale', $locale)->where('path', 'like', $path.'/%');
    }

    public function type(): ContentType
    {
        return app(ContentTypes::class)->get($this->type);
    }

    public function state(): EntryState
    {
        if ($this->status !== EntryStatus::Published || $this->published_at === null) {
            return EntryState::Draft;
        }

        return $this->published_at->isFuture() ? EntryState::Scheduled : EntryState::Published;
    }

    public function isPublic(): bool
    {
        return ! $this->trashed()
            && $this->status === EntryStatus::Published
            && $this->published_at !== null
            && ! $this->published_at->isFuture();
    }

    /**
     * How deep the record sits: 0 at the top of the tree.
     */
    public function depth(): int
    {
        return substr_count($this->path, '/');
    }

    /**
     * The records under this one (same type and language, path starting with this path and `/`) in the same state:
     * the live ones for a live record (what trashing takes along), the trashed ones for a trashed record. One query.
     */
    public function descendantsCount(): int
    {
        return $this->descendants()
            ->when($this->trashed(), fn (Builder $query) => $query->onlyTrashed())
            ->count();
    }

    /**
     * Records under this one, by path: same type and language, path starting with this path and `/`. Live ones
     * only unless the caller asks for trashed ones.
     *
     * @return Builder<Entry>
     */
    public function descendants(): Builder
    {
        return self::query()->under($this->type, $this->locale, $this->path);
    }

    /**
     * The `<title>` of the public page: the SEO title alone when set, else the title, " - " and the site name. The
     * form's search preview (resources/js/entry-form.js) follows the same rule.
     */
    public function documentTitle(): string
    {
        if (filled($this->seo_title) && $this->type()->supports('seo')) {
            return (string) $this->seo_title;
        }

        return $this->title.' - '.config('app.name');
    }

    /**
     * The public address in the record's language (the `content` route): the language prefix (none for the default
     * one), the base of the type, then the path. No query.
     */
    public function url(bool $absolute = true): string
    {
        $base = trim((string) $this->type()->base($this->locale), '/');

        return app(ContentLocales::class)->route(
            'content',
            ['path' => $base === '' ? $this->path : $base.'/'.$this->path],
            $this->locale,
            $absolute,
        );
    }

    /**
     * A field's value: from the group for a shared field, from the record otherwise.
     */
    public function fieldValue(string $name): mixed
    {
        if ($this->type()->field($name)?->isShared) {
            return $this->group->shared[$name] ?? null;
        }

        return $this->fields[$name] ?? null;
    }

    /**
     * The translation of the record's parent in a language: the parent a translation of this record gets there.
     * Null without a parent or when it has none in that language. One query.
     */
    public function parentTranslation(string $locale): ?self
    {
        if ($this->parent_id === null) {
            return null;
        }

        return self::query()
            ->where('locale', $locale)
            ->where('entry_group_id', self::query()->select('entry_group_id')->whereKey($this->parent_id))
            ->first();
    }

    /**
     * The form data of a new translation of this record into a language: a copy of its texts, image, author, SEO
     * and fields (shared ones carry the group's values), the parent's translation in that language (or none), an
     * empty slug and the draft status. Nothing is stored.
     *
     * @return array<string, mixed>
     */
    public function copyFor(string $locale): array
    {
        $parentId = $this->parentTranslation($locale)?->id;

        $fields = [];

        foreach ($this->type()->fields() as $field) {
            $fields[$field->name] = $field->toForm($this->fieldValue($field->name));
        }

        return [
            'title' => $this->title,
            'slug' => '',
            'parent_id' => $parentId,
            'terms' => app(ContentTypes::class)->taxonomiesOf($this->type) === [] ? [] : $this->termIdsIn($locale),
            'author_id' => $this->author_id,
            'image_id' => $this->image_id,
            'status' => EntryStatus::Draft->value,
            'published_at' => null,
            'excerpt' => $this->excerpt,
            'seo_title' => $this->seo_title,
            'seo_description' => $this->seo_description,
            'body' => $this->body,
            'fields' => $fields,
        ];
    }

    /**
     * "Page: About us", by the type's label.
     */
    public function mediaUsageLabel(string $field): string
    {
        return __('hata::content.usage.entry', ['type' => $this->typeLabel(), 'title' => $this->title]);
    }

    /**
     * The record's edit page (read-only while it is in the trash), for a viewer who may view the type.
     */
    public function mediaUsageUrl(string $field, ?AdminUser $viewer): ?string
    {
        // A type that is switched off has no admin pages to link to.
        if ($viewer === null || ! app(ContentTypes::class)->isActive($this->type) || ! Gate::forUser($viewer)->allows('view', $this)) {
            return null;
        }

        return route('admin.content.edit', ['type' => $this->type, 'entry' => $this->id]);
    }

    /**
     * Stops using a file that is being deleted: the image is cleared, the body loses the file's images, a file
     * field is emptied and a gallery loses the item. Runs inside the delete's transaction.
     */
    public function forgetMedia(string $field, int $mediaId): void
    {
        if ($field === 'image' && $this->image_id === $mediaId) {
            $this->image_id = null;
        } elseif ($field === 'body') {
            $this->body = app(BodySanitizer::class)->withoutMedia($this->body, $mediaId);
        } elseif (str_starts_with($field, 'fields.')) {
            $name = substr($field, strlen('fields.'));
            $definition = app(ContentTypes::class)->find($this->type)?->field($name);
            $fields = $this->fields ?? [];

            if ($definition !== null && array_key_exists($name, $fields)) {
                $fields[$name] = $definition->withoutMedia($fields[$name], $mediaId);
                $this->fields = $fields;
            }
        }

        if ($this->isDirty()) {
            $this->save();
        }
    }

    /**
     * The type's label, or its key when the type is no longer registered.
     */
    public function typeLabel(): string
    {
        return app(ContentTypes::class)->find($this->type)?->label() ?? $this->type;
    }
}

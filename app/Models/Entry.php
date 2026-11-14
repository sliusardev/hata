<?php

namespace App\Models;

use App\Enums\EntryState;
use App\Enums\EntryStatus;
use App\Models\Concerns\HasSearchIndex;
use App\Support\Content\ContentType;
use App\Support\Content\ContentTypes;
use App\Support\Localization\ContentLocales;
use Database\Factories\EntryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

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
 * @property-read Collection<int, Entry> $translations
 */
#[Fillable([
    'entry_group_id', 'type', 'locale', 'parent_id', 'author_id', 'title', 'slug', 'path', 'body', 'excerpt',
    'seo_title', 'seo_description', 'image_id', 'fields', 'status', 'published_at',
])]
class Entry extends Model
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
     * @return BelongsTo<User, $this>
     */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }

    /**
     * @return BelongsTo<Media, $this>
     */
    public function image(): BelongsTo
    {
        return $this->belongsTo(Media::class, 'image_id');
    }

    /**
     * The other records of the group (trashed ones excluded).
     *
     * @return HasMany<Entry, $this>
     */
    public function translations(): HasMany
    {
        return $this->hasMany(self::class, 'entry_group_id', 'entry_group_id')->whereKeyNot($this->getKey());
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
        $query->where('status', EntryStatus::Published->value)->where('published_at', '<=', now());
    }

    public function type(): ContentType
    {
        return app(ContentTypes::class)->get($this->type);
    }

    public function state(): EntryState
    {
        if ($this->status !== EntryStatus::Published) {
            return EntryState::Draft;
        }

        return $this->published_at !== null && $this->published_at->isFuture()
            ? EntryState::Scheduled
            : EntryState::Published;
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
     * The public address in the record's language: the language prefix (none for the default one), the base of
     * the type, then the path.
     */
    public function url(bool $absolute = true): string
    {
        $segments = [];

        if ($this->locale !== app(ContentLocales::class)->defaultCode()) {
            $segments[] = $this->locale;
        }

        $base = $this->type()->base($this->locale);

        if ($base !== null && $base !== '') {
            $segments[] = trim($base, '/');
        }

        $segments[] = $this->path;
        $path = '/'.implode('/', $segments);

        return $absolute ? url($path) : $path;
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
}

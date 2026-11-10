<?php

namespace App\Models;

use Database\Factories\MediaFolderFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int|null $parent_id
 * @property string $name
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read int|null $media_count
 */
#[Fillable(['parent_id', 'name'])]
class MediaFolder extends Model
{
    /** @use HasFactory<MediaFolderFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['parent_id' => 'integer'];
    }

    /**
     * @return BelongsTo<MediaFolder, $this>
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    /**
     * @return HasMany<MediaFolder, $this>
     */
    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    /**
     * @return HasMany<Media, $this>
     */
    public function media(): HasMany
    {
        return $this->hasMany(Media::class, 'folder_id');
    }

    /**
     * All folders ordered by name, with their file counts.
     *
     * @return Collection<int, MediaFolder>
     */
    public static function tree(): Collection
    {
        return self::query()->withCount('media')->orderBy('name')->get();
    }

    /**
     * Ids of every folder below this one, at any depth (self excluded).
     *
     * @return array<int, int>
     */
    public function descendantIds(): array
    {
        $byParent = static::query()
            ->whereNotNull('parent_id')
            ->get(['id', 'parent_id'])
            ->groupBy('parent_id');

        $ids = [];
        $queue = [$this->id];

        while ($queue !== []) {
            $current = array_shift($queue);

            foreach ($byParent[$current] ?? [] as $child) {
                if ($child->id === $this->id || in_array($child->id, $ids, true)) {
                    continue;
                }

                $ids[] = $child->id;
                $queue[] = $child->id;
            }
        }

        return $ids;
    }

    /**
     * What deleting this folder removes: itself and its subfolders, their files, and how many of those files are in use.
     *
     * @return array{folders: int, files: int, used: int}
     */
    public function deletionSummary(): array
    {
        $ids = [$this->id, ...$this->descendantIds()];
        $files = Media::query()->whereIn('folder_id', $ids);

        return [
            'folders' => count($ids),
            'files' => (clone $files)->count(),
            'used' => (clone $files)->whereHas('usages')->count(),
        ];
    }

    /**
     * The folder chain from the root down to this folder.
     *
     * @return \Illuminate\Support\Collection<int, MediaFolder>
     */
    public function breadcrumb(): \Illuminate\Support\Collection
    {
        $all = static::query()->get()->keyBy('id');
        $chain = collect();
        $current = $this;

        while ($current !== null && ! $chain->contains('id', $current->id)) {
            $chain->prepend($current);
            $current = $current->parent_id !== null ? $all->get($current->parent_id) : null;
        }

        return $chain->values();
    }
}

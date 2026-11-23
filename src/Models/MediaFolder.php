<?php

namespace Hata\Models;

use Hata\Database\Factories\MediaFolderFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
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
#[UseFactory(MediaFolderFactory::class)]
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
        $counts = self::fileCounts();

        return self::query()->orderBy('name')->get()->each(
            fn (MediaFolder $folder) => $folder->setFileCount($counts[$folder->id] ?? 0),
        );
    }

    /**
     * How many files each folder holds, by folder id (folders without files are absent): one grouped query
     * instead of a count per folder.
     *
     * @return array<int, int>
     */
    public static function fileCounts(): array
    {
        return Media::query()
            ->toBase()
            ->whereNotNull('folder_id')
            ->groupBy('folder_id')
            ->selectRaw('folder_id, count(*) as files')
            ->pluck('files', 'folder_id')
            ->map(fn (mixed $count): int => (int) $count)
            ->all();
    }

    /**
     * Set `media_count` without marking the folder as changed.
     */
    public function setFileCount(int $count): static
    {
        return $this->setAttribute('media_count', $count)->syncOriginalAttribute('media_count');
    }

    /**
     * Folders in tree order (each followed by its subfolders), with their depth, for the library and the picker.
     * Without counts it is one query on the folders table and never touches the files.
     *
     * @return array<int, array{folder: MediaFolder, depth: int}>
     */
    public static function treeRows(bool $withCounts = true): array
    {
        $folders = $withCounts ? self::tree() : self::query()->orderBy('name')->get();
        $byParent = $folders->groupBy(fn (MediaFolder $folder): int => $folder->parent_id ?? 0);
        $rows = [];

        $walk = function (int $parentId, int $depth) use (&$walk, &$rows, $byParent): void {
            foreach ($byParent->get($parentId, []) as $folder) {
                $rows[] = ['folder' => $folder, 'depth' => $depth];
                $walk($folder->id, $depth + 1);
            }
        };

        $walk(0, 0);

        return $rows;
    }

    /**
     * Direct subfolders of a folder (null = the top level), taken from treeRows() rows, by name, each with
     * `children_count` set: the folder tiles of the library page and the media picker.
     *
     * @param  array<int, array{folder: MediaFolder, depth: int}>  $rows
     * @return \Illuminate\Support\Collection<int, MediaFolder>
     */
    public static function childrenOf(array $rows, ?int $parentId): \Illuminate\Support\Collection
    {
        $folders = collect($rows)->pluck('folder');
        $childCounts = $folders->countBy(fn (MediaFolder $folder): int => $folder->parent_id ?? 0);

        return $folders
            ->where('parent_id', $parentId)
            ->each(fn (MediaFolder $folder) => $folder->setAttribute('children_count', $childCounts->get($folder->id, 0)))
            ->values();
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
     * The folder chain from the root down to this folder. Pass the folders already loaded to save the query.
     *
     * @param  iterable<MediaFolder>|null  $folders
     * @return \Illuminate\Support\Collection<int, MediaFolder>
     */
    public function breadcrumb(?iterable $folders = null): \Illuminate\Support\Collection
    {
        $all = collect($folders ?? static::query()->get())->keyBy('id');
        $chain = collect();
        $current = $this;

        while ($current !== null && ! $chain->contains('id', $current->id)) {
            $chain->prepend($current);
            $current = $current->parent_id !== null ? $all->get($current->parent_id) : null;
        }

        return $chain->values();
    }
}

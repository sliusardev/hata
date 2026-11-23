<?php

namespace Hata\Models;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * A replaced object on a cloud disk, kept until `delete_after` so pages rendered before the change (and CDN caches)
 * still find it, then removed by PruneMediaDeletions. A move between disks also records each copy it is about to write
 * (due in a day, on either disk) and drops those rows when it commits or cleans up, so a process that dies mid-copy
 * leaves no orphan behind: the prune never deletes a path a media row points at.
 *
 * @property int $id
 * @property string $disk
 * @property string $path
 * @property CarbonImmutable $delete_after
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['disk', 'path', 'delete_after'])]
class MediaPendingDeletion extends Model
{
    /** Days a replaced cloud object is kept before it may be deleted. */
    public const int KEEP_DAYS = 7;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'delete_after' => 'datetime',
        ];
    }

    /**
     * Schedule the given objects of a disk for deletion after KEEP_DAYS, or at $deleteAfter.
     *
     * @param  list<string>  $paths
     */
    public static function schedule(string $disk, array $paths, ?CarbonInterface $deleteAfter = null): void
    {
        if ($paths === []) {
            return;
        }

        $now = now();
        $deleteAfter ??= $now->addDays(self::KEEP_DAYS);

        static::query()->insert(array_map(fn (string $path): array => [
            'disk' => $disk,
            'path' => $path,
            'delete_after' => $deleteAfter,
            'created_at' => $now,
            'updated_at' => $now,
        ], $paths));
    }
}

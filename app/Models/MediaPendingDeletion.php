<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * A replaced object on a cloud disk, kept until `delete_after` so pages rendered before the change (and CDN caches)
 * still find it, then removed by PruneMediaDeletions.
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
     * Schedule the given objects of a disk for deletion after KEEP_DAYS.
     *
     * @param  list<string>  $paths
     */
    public static function schedule(string $disk, array $paths): void
    {
        if ($paths === []) {
            return;
        }

        $now = now();
        $deleteAfter = $now->addDays(self::KEEP_DAYS);

        static::query()->insert(array_map(fn (string $path): array => [
            'disk' => $disk,
            'path' => $path,
            'delete_after' => $deleteAfter,
            'created_at' => $now,
            'updated_at' => $now,
        ], $paths));
    }
}

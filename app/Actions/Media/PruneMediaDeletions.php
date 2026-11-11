<?php

namespace App\Actions\Media;

use App\Models\Media;
use App\Models\MediaPendingDeletion;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Deletes the replaced cloud objects whose delay is over (MediaPendingDeletion), oldest first. Runs from
 * `media:prune-deleted` (scheduled daily), at the end of each optimize batch and from the library page at most once
 * an hour, so no worker or cron is needed: the delay is a minimum, not an exact time.
 */
class PruneMediaDeletions
{
    /** Cache key that limits the library page to one prune an hour. */
    public const string PAGE_GUARD = 'media-prune-deletions';

    /** Rows the library page handles per prune, so the deferred work after its response stays short. */
    public const int PAGE_LIMIT = 50;

    /** Hours a failed deletion waits before it is tried again, so it doesn't block the rows behind it. */
    private const int RETRY_HOURS = 24;

    /**
     * @return int Number of pending rows removed (their objects deleted, already gone, or still used by a media row).
     */
    public function handle(int $limit = 200): int
    {
        $due = MediaPendingDeletion::query()
            ->where('delete_after', '<=', now())
            ->orderBy('delete_after')
            ->orderBy('id')
            ->limit($limit)
            ->get();

        $done = 0;

        foreach ($due->groupBy('disk') as $diskName => $rows) {
            $disk = (string) $diskName;
            $paths = array_values(array_unique($rows->map(fn (MediaPendingDeletion $row): string => $row->path)->all()));
            $used = $this->usedPaths($disk, $paths);
            $ids = $rows->pluck('id')->all();

            try {
                // A missing object is fine: deleting it again is a no-op.
                Storage::disk($disk)->delete(array_values(array_diff($paths, $used)));
            } catch (Throwable $e) {
                report($e);

                MediaPendingDeletion::query()->whereKey($ids)->update(['delete_after' => now()->addHours(self::RETRY_HOURS)]);

                continue;
            }

            $done += MediaPendingDeletion::query()->whereKey($ids)->delete();
        }

        return $done;
    }

    /**
     * Paths among $paths that a media row on the disk still points at; never deleted, whatever the schedule says.
     *
     * @param  list<string>  $paths
     * @return list<string>
     */
    private function usedPaths(string $disk, array $paths): array
    {
        $rows = Media::query()
            ->where('disk', $disk)
            ->where(fn ($query) => $query->whereIn('path', $paths)->orWhereIn('thumb_path', $paths)->orWhereIn('original_path', $paths))
            ->get(['path', 'thumb_path', 'original_path']);

        return array_values(array_intersect($paths, $rows->flatMap(fn (Media $media): array => [$media->path, $media->thumb_path, $media->original_path])->all()));
    }
}

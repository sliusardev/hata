<?php

namespace Hata\Actions\Media;

use Hata\Contracts\AdminUser;
use Hata\Models\Media;
use Hata\Models\MediaUsage;
use Hata\Support\Media\MediaDisks;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Throwable;

class DeleteMedia
{
    /**
     * Stored files are removed in `DB::afterCommit`, so inside an enclosing
     * transaction they go only after the outermost commit and stay on rollback.
     * The files are those the rows point at when they are deleted (re-read with a row lock), not those of the
     * instances passed in.
     *
     * @param  iterable<Media>|Media  $media
     * @return int Number of deleted rows.
     */
    public function handle(AdminUser $actor, iterable|Media $media): int
    {
        $items = $media instanceof Media ? [$media] : array_values([...$media]);

        // The policy does not depend on the row, so one check covers the whole set.
        Gate::forUser($actor)->authorize('delete', $items[0] ?? new Media);

        return DB::transaction(function () use ($items): int {
            // Before the delete: the usage rows cascade away with the media rows.
            MediaUsage::forgetAll($items);

            $count = 0;
            $filesByDisk = [];

            foreach (array_chunk($items, 500) as $chunk) {
                $ids = array_map(fn (Media $item): int => $item->id, $chunk);

                // The stored disk and paths, read under the row lock: a move or rebuild may have committed others
                // since the instances were loaded, and deleting the stale ones would orphan the current objects.
                $stored = Media::query()->whereKey($ids)->lockForUpdate()->get(['id', 'disk', 'path', 'original_path', 'thumb_path']);

                foreach ($stored as $row) {
                    $originalsDisk = MediaDisks::originalsDisk($row->disk);

                    foreach ([[$row->disk, $row->path], [$originalsDisk, $row->original_path], [$row->disk, $row->thumb_path]] as [$fileDisk, $file]) {
                        if ($file !== null && $file !== '') {
                            $filesByDisk[$fileDisk][] = $file;
                        }
                    }
                }

                $count += Media::query()->whereKey($ids)->delete();
            }

            DB::afterCommit(function () use ($filesByDisk): void {
                foreach ($filesByDisk as $disk => $files) {
                    try {
                        Storage::disk((string) $disk)->delete($files);
                    } catch (Throwable $e) {
                        report($e);
                    }
                }
            });

            return $count;
        });
    }
}

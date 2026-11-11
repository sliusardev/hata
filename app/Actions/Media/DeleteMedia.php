<?php

namespace App\Actions\Media;

use App\Models\Media;
use App\Models\MediaUsage;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Throwable;

class DeleteMedia
{
    /**
     * Stored files are removed in `DB::afterCommit`, so inside an enclosing
     * transaction they go only after the outermost commit and stay on rollback.
     *
     * @param  iterable<Media>|Media  $media
     * @return int Number of deleted rows.
     */
    public function handle(User $actor, iterable|Media $media): int
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
                foreach ($chunk as $item) {
                    foreach ([$item->path, $item->original_path, $item->thumb_path] as $file) {
                        if ($file !== null && $file !== '') {
                            $filesByDisk[$item->disk][] = $file;
                        }
                    }
                }

                $count += Media::query()->whereKey(array_map(fn (Media $item): int => $item->id, $chunk))->delete();
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

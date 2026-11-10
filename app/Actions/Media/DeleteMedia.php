<?php

namespace App\Actions\Media;

use App\Models\Media;
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
        $items = $media instanceof Media ? [$media] : [...$media];

        foreach ($items as $item) {
            Gate::forUser($actor)->authorize('delete', $item);
        }

        return DB::transaction(function () use ($items): int {
            $count = 0;

            foreach ($items as $item) {
                $files = array_values(array_filter([$item->path, $item->original_path, $item->thumb_path]));
                $disk = $item->disk;

                $item->delete();
                $count++;

                DB::afterCommit(function () use ($disk, $files): void {
                    try {
                        Storage::disk($disk)->delete($files);
                    } catch (Throwable $e) {
                        report($e);
                    }
                });
            }

            return $count;
        });
    }
}

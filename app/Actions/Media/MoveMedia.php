<?php

namespace App\Actions\Media;

use App\Models\Media;
use App\Models\MediaFolder;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class MoveMedia
{
    /**
     * @param  iterable<Media>|Media  $media
     */
    public function handle(User $actor, iterable|Media $media, ?MediaFolder $folder): void
    {
        $items = $media instanceof Media ? [$media] : [...$media];

        foreach ($items as $item) {
            Gate::forUser($actor)->authorize('update', $item);
        }

        DB::transaction(function () use ($items, $folder): void {
            foreach ($items as $item) {
                $item->forceFill(['folder_id' => $folder?->id])->save();
            }
        });
    }
}

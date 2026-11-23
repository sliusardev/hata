<?php

namespace App\Actions\Media;

use App\Models\Media;
use App\Models\MediaFolder;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

class MoveMedia
{
    /**
     * Move the files into the folder (null = no folder) with one statement for the whole selection: only the
     * folder changes, so no model needs saving on its own.
     *
     * @param  iterable<Media>|Media  $media
     */
    public function handle(User $actor, iterable|Media $media, ?MediaFolder $folder): void
    {
        $items = $media instanceof Media ? [$media] : [...$media];

        foreach ($items as $item) {
            Gate::forUser($actor)->authorize('update', $item);
        }

        if ($items === []) {
            return;
        }

        Media::query()
            ->whereKey(array_map(fn (Media $item): int => $item->id, $items))
            ->update(['folder_id' => $folder?->id]);

        foreach ($items as $item) {
            $item->forceFill(['folder_id' => $folder?->id])->syncOriginalAttribute('folder_id');
        }
    }
}

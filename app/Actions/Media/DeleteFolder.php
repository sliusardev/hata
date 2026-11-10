<?php

namespace App\Actions\Media;

use App\Models\Media;
use App\Models\MediaFolder;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class DeleteFolder
{
    public function __construct(private DeleteMedia $deleteMedia) {}

    /**
     * @return array{folders: int, files: int}
     */
    public function handle(User $actor, MediaFolder $folder): array
    {
        Gate::forUser($actor)->authorize('deleteFolders', Media::class);

        return DB::transaction(function () use ($actor, $folder): array {
            $ids = [$folder->id, ...$folder->descendantIds()];

            $files = $this->deleteMedia->handle($actor, Media::query()->whereIn('folder_id', $ids)->get());

            // Descendant ids come breadth-first, so the reverse order removes the deepest first.
            foreach (array_reverse($ids) as $id) {
                MediaFolder::query()->whereKey($id)->delete();
            }

            return ['folders' => count($ids), 'files' => $files];
        });
    }
}

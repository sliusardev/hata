<?php

namespace Hata\Actions\Media;

use Hata\Contracts\AdminUser;
use Hata\Models\Media;
use Hata\Models\MediaFolder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class DeleteFolder
{
    public function __construct(private DeleteMedia $deleteMedia) {}

    /**
     * @return array{folders: int, files: int}
     */
    public function handle(AdminUser $actor, MediaFolder $folder): array
    {
        Gate::forUser($actor)->authorize('deleteFolders', Media::class);

        return DB::transaction(function () use ($actor, $folder): array {
            $ids = [$folder->id, ...$folder->descendantIds()];

            $items = Media::query()
                ->whereIn('folder_id', $ids)
                ->select(['id', 'disk', 'path', 'original_path', 'thumb_path'])
                ->lazyById(500)
                ->all();

            $files = $this->deleteMedia->handle($actor, $items);

            // Descendant ids come breadth-first, so the reverse order removes the deepest first.
            foreach (array_chunk(array_reverse($ids), 500) as $chunk) {
                MediaFolder::query()->whereKey($chunk)->delete();
            }

            return ['folders' => count($ids), 'files' => $files];
        });
    }
}

<?php

namespace Hata\Actions\Media;

use App\Models\User;
use Hata\Models\Media;
use Hata\Models\MediaFolder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class MoveFolder
{
    public function __construct(private FolderNameValidator $names) {}

    /**
     * Move a folder (with everything in it) under another folder; a null target is the top level.
     *
     * @throws ValidationException
     */
    public function handle(User $actor, MediaFolder $folder, ?MediaFolder $target): MediaFolder
    {
        Gate::forUser($actor)->authorize('manageFolders', Media::class);

        return DB::transaction(function () use ($folder, $target): MediaFolder {
            $folder = MediaFolder::query()->lockForUpdate()->findOrFail($folder->id);
            $target = $target === null ? null : MediaFolder::query()->lockForUpdate()->findOrFail($target->id);

            if ($target !== null && ($target->id === $folder->id || in_array($target->id, $folder->descendantIds(), true))) {
                throw ValidationException::withMessages(['folder' => __('media.errors.folder_into_itself')]);
            }

            if ($folder->parent_id === $target?->id) {
                return $folder;
            }

            $this->names->validate($folder->name, $target?->id, $folder->id);

            try {
                $folder->parent_id = $target?->id;
                $folder->save();
            } catch (UniqueConstraintViolationException) {
                throw ValidationException::withMessages(['name' => __('media.errors.folder_exists')]);
            }

            return $folder;
        });
    }
}

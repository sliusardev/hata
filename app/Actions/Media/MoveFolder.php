<?php

namespace App\Actions\Media;

use App\Models\Media;
use App\Models\MediaFolder;
use App\Models\User;
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

        if ($target !== null && ($target->id === $folder->id || in_array($target->id, $folder->descendantIds(), true))) {
            throw ValidationException::withMessages(['folder' => __('media.errors.folder_into_itself')]);
        }

        if ($folder->parent_id === $target?->id) {
            return $folder;
        }

        $this->names->validate($folder->name, $target?->id, $folder->id);

        $folder->parent_id = $target?->id;
        $folder->save();

        return $folder;
    }
}

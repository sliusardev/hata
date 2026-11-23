<?php

namespace Hata\Actions\Media;

use Hata\Contracts\AdminUser;
use Hata\Models\Media;
use Hata\Models\MediaFolder;
use Illuminate\Support\Facades\Gate;

class RenameFolder
{
    public function __construct(private FolderNameValidator $names) {}

    public function handle(AdminUser $actor, MediaFolder $folder, string $name): MediaFolder
    {
        Gate::forUser($actor)->authorize('manageFolders', Media::class);

        $folder->name = $this->names->validate($name, $folder->parent_id, $folder->id);
        $folder->save();

        return $folder;
    }
}

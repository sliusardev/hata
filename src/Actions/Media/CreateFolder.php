<?php

namespace Hata\Actions\Media;

use Hata\Contracts\AdminUser;
use Hata\Models\Media;
use Hata\Models\MediaFolder;
use Illuminate\Support\Facades\Gate;

class CreateFolder
{
    public function __construct(private FolderNameValidator $names) {}

    public function handle(AdminUser $actor, string $name, ?MediaFolder $parent): MediaFolder
    {
        Gate::forUser($actor)->authorize('manageFolders', Media::class);

        $name = $this->names->validate($name, $parent?->id);

        return MediaFolder::query()->create(['parent_id' => $parent?->id, 'name' => $name]);
    }
}

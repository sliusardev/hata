<?php

namespace App\Actions\Media;

use App\Models\Media;
use App\Models\MediaFolder;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

class CreateFolder
{
    public function __construct(private FolderNameValidator $names) {}

    public function handle(User $actor, string $name, ?MediaFolder $parent): MediaFolder
    {
        Gate::forUser($actor)->authorize('manageFolders', Media::class);

        $name = $this->names->validate($name, $parent?->id);

        return MediaFolder::query()->create(['parent_id' => $parent?->id, 'name' => $name]);
    }
}

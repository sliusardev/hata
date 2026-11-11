<?php

namespace App\Policies;

use App\Models\Media;
use App\Models\User;

/**
 * Permission checks for the media library.
 */
class MediaPolicy
{
    public function viewAny(User $actor): bool
    {
        return $actor->can('media.view');
    }

    public function create(User $actor): bool
    {
        return $actor->can('media.upload');
    }

    public function update(User $actor, Media $media): bool
    {
        return $actor->can('media.edit');
    }

    public function delete(User $actor, Media $media): bool
    {
        return $actor->can('media.delete');
    }

    public function manageFolders(User $actor): bool
    {
        return $actor->can('media.edit');
    }

    /**
     * Run the optimization of existing files.
     */
    public function optimize(User $actor): bool
    {
        return $actor->can('media.edit');
    }

    /**
     * Move files between the local disk and the cloud disk.
     */
    public function moveToDisk(User $actor): bool
    {
        return $actor->can('media.edit');
    }

    public function deleteFolders(User $actor): bool
    {
        return $actor->can('media.edit') && $actor->can('media.delete');
    }
}

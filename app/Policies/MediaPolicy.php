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

    public function deleteFolders(User $actor): bool
    {
        return $actor->can('media.edit') && $actor->can('media.delete');
    }
}

<?php

namespace Hata\Policies;

use Hata\Contracts\AdminUser;
use Hata\Models\Media;

/**
 * Permission checks for the media library.
 */
class MediaPolicy
{
    public function viewAny(AdminUser $actor): bool
    {
        return $actor->can('media.view');
    }

    public function create(AdminUser $actor): bool
    {
        return $actor->can('media.upload');
    }

    public function update(AdminUser $actor, Media $media): bool
    {
        return $actor->can('media.edit');
    }

    public function delete(AdminUser $actor, Media $media): bool
    {
        return $actor->can('media.delete');
    }

    public function manageFolders(AdminUser $actor): bool
    {
        return $actor->can('media.edit');
    }

    /**
     * Run the optimization of existing files.
     */
    public function optimize(AdminUser $actor): bool
    {
        return $actor->can('media.edit');
    }

    /**
     * Move files between the local disk and the cloud disk.
     */
    public function moveToDisk(AdminUser $actor): bool
    {
        return $actor->can('media.edit');
    }

    public function deleteFolders(AdminUser $actor): bool
    {
        return $actor->can('media.edit') && $actor->can('media.delete');
    }
}

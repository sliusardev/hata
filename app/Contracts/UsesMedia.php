<?php

namespace App\Contracts;

use App\Models\User;

/**
 * A model that references media files through `media_usages`.
 */
interface UsesMedia
{
    /**
     * The name shown in "Where it's used" for the given usage field.
     */
    public function mediaUsageLabel(string $field): string;

    /**
     * Where the viewer can open the place that uses the media, or null when there is no such page or the viewer may not open it.
     */
    public function mediaUsageUrl(string $field, ?User $viewer): ?string;

    /**
     * Clear the field's reference to a media file that is about to be deleted.
     */
    public function forgetMedia(string $field, int $mediaId): void;
}

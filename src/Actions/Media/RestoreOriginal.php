<?php

namespace Hata\Actions\Media;

use Hata\Contracts\AdminUser;
use Hata\Models\Media;
use Hata\Support\Media\MediaSource;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class RestoreOriginal
{
    public function __construct(private RebuildMediaFile $rebuilder) {}

    /**
     * Rebuild the file from the kept original, dropping every edit.
     *
     * @throws ValidationException when no original file is kept
     */
    public function handle(AdminUser $actor, Media $media): Media
    {
        Gate::forUser($actor)->authorize('update', $media);

        $original = $media->original_path;

        if ($original === null || $original === '' || ! Storage::disk($media->originalsDisk())->exists($original)) {
            throw ValidationException::withMessages(['original' => __('hata::media.errors.no_original')]);
        }

        $source = MediaSource::for($media);

        return $this->rebuilder->handle($media, $source->contents, $source->mime, null);
    }
}

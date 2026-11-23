<?php

namespace App\Actions\Media;

use App\Enums\MediaKind;
use App\Exceptions\MediaUploadException;
use App\Models\Media;
use App\Models\User;
use App\Support\Media\MediaNames;
use App\Support\Media\MediaUploadChecks;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;

class ReplaceMediaFile
{
    public function __construct(private RebuildMediaFile $rebuilder) {}

    /**
     * Put a new file in place of the media row's file, keeping its id, key, folder, title, alt and usages.
     *
     * The new file must be of the same kind, and on the same side of the raster/SVG line (an SVG never lands on the
     * row's disk when that disk publishes raster images). Edits are cleared. As on upload, an image is kept as the new original
     * when originals are kept; otherwise the old original is dropped.
     *
     * @param  int|null  $pickedSize  Size of the file the user picked, when the browser shrank it before upload.
     * @param  string|null  $pickedExtension  Extension of the file the user picked, when the browser converted it.
     *
     * @throws MediaUploadException
     */
    public function handle(User $actor, Media $media, UploadedFile $file, ?int $pickedSize = null, ?string $pickedExtension = null): Media
    {
        Gate::forUser($actor)->authorize('update', $media);

        $type = MediaUploadChecks::validate($file, $pickedSize);

        if ($type['kind'] !== $media->kind) {
            throw MediaUploadException::otherKind($media->kind);
        }

        if (($type['mime'] === 'image/svg+xml') !== ($media->mime === 'image/svg+xml')) {
            throw MediaUploadException::svgBoundary();
        }

        $contents = (string) file_get_contents($file->getRealPath());
        $keepOriginal = $type['kind'] === MediaKind::Image
            && config('media.optimize.enabled')
            && config('media.optimize.keep_originals');

        return $this->rebuilder->handle(
            $media,
            $contents,
            $type['mime'],
            null,
            newName: fn (string $extension): string => MediaNames::unique($file->getClientOriginalName(), $extension, ignoreId: $media->id),
            newOriginal: $keepOriginal ? $contents : null,
            dropOriginal: ! $keepOriginal,
            attributes: [
                'original_size' => $pickedSize ?? (int) $file->getSize(),
                'uploaded_extension' => MediaUploadChecks::pickedExtension($pickedExtension) ?? $type['ext'],
            ],
        );
    }
}

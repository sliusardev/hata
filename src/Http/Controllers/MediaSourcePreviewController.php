<?php

namespace Hata\Http\Controllers;

use Hata\Models\Media;
use Hata\Support\Media\MediaSource;
use Hata\Support\Media\MemoryGuard;
use Illuminate\Image\ImageException;
use Illuminate\Support\Facades\Image;
use Imagick;
use ImagickException;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

class MediaSourcePreviewController extends Controller
{
    /**
     * The longest side of the preview, in pixels.
     */
    private const int MAX_SIDE = 2048;

    /**
     * A browser-displayable, oriented, downscaled copy of the media's source, for the crop page.
     */
    public function __invoke(Media $media, MemoryGuard $memory): Response
    {
        abort_unless($media->isEditable(), 404);

        try {
            $source = MediaSource::for($media);
        } catch (RuntimeException) {
            abort(404);
        }

        $size = $this->dimensions($source->contents, $source->mime);

        if ($size === null || ! $memory->canDecode(...$size)) {
            abort(422, __('hata::media.errors.too_large_to_edit'));
        }

        try {
            [$width, $height] = $source->orientedSize();
            $image = Image::fromBytes($source->contents)->orient();

            if (max($width, $height) > self::MAX_SIDE) {
                $image = $image->scale(self::MAX_SIDE, self::MAX_SIDE);
            }

            $hasAlpha = in_array($source->mime, ['image/png', 'image/webp'], true);
            $bytes = ($hasAlpha ? $image->toPng() : $image->toJpeg())->toBytes();
        } catch (ImageException) {
            abort(422, __('hata::media.errors.too_large_to_edit'));
        }

        return response($bytes, 200, [
            'Content-Type' => $hasAlpha ? 'image/png' : 'image/jpeg',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
            'X-Source-Width' => (string) $width,
            'X-Source-Height' => (string) $height,
        ]);
    }

    /**
     * @return array{int, int}|null
     */
    private function dimensions(string $contents, string $mime): ?array
    {
        $size = @getimagesizefromstring($contents);

        if ($size !== false) {
            return [$size[0], $size[1]];
        }

        if (! in_array($mime, ['image/heic', 'image/heif'], true) || ! extension_loaded('imagick')) {
            return null;
        }

        try {
            $imagick = new Imagick;
            $imagick->pingImageBlob($contents);

            return [$imagick->getImageWidth(), $imagick->getImageHeight()];
        } catch (ImagickException) {
            return null;
        }
    }
}

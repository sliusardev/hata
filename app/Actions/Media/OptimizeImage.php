<?php

namespace App\Actions\Media;

use App\Support\Media\CaptureThumbnail;
use App\Support\Media\ImageEdits;
use App\Support\Media\MediaTypes;
use App\Support\Media\MemoryGuard;
use App\Support\Media\OptimizedImage;
use App\Support\Media\StripMetadata;
use finfo;
use Illuminate\Image\Image as PendingImage;
use Illuminate\Image\ImageException;
use Illuminate\Support\Facades\Image;
use Imagick;
use ImagickException;
use Intervention\Image\Drivers\Imagick\Modifiers\StripMetaModifier;
use Intervention\Image\Interfaces\ImageInterface;
use InvalidArgumentException;

/**
 * Scales, re-encodes and strips raster images according to the media.optimize settings.
 */
class OptimizeImage
{
    /**
     * Raster MIME types the optimizer re-encodes.
     *
     * @var list<string>
     */
    private const array RASTER = ['image/jpeg', 'image/png', 'image/webp', 'image/gif', 'image/avif', 'image/heic', 'image/heif'];

    /**
     * MIME types browsers can't show, so they are always converted: to WebP, or to JPEG when the format
     * setting keeps the original. This happens even with optimization off, and the input is never kept.
     *
     * @var list<string>
     */
    public const array MUST_CONVERT = ['image/heic', 'image/heif'];

    public function __construct(private MemoryGuard $memory) {}

    /**
     * @param  array<string, mixed>|null  $edits  The image editor's crop/rotate/flip (see ImageEdits). They are always applied,
     *                                            even with optimization off, after orienting and before the max-side scale.
     *
     * @throws InvalidArgumentException when the edits are malformed
     */
    public function handle(string $contents, string $mime, ?array $edits = null): OptimizedImage
    {
        $raster = in_array($mime, self::RASTER, true);
        $size = $raster ? $this->dimensions($contents, $mime) : null;
        [$width, $height] = $size ?? [null, null];
        $enabled = (bool) config('media.optimize.enabled');
        $mustConvert = in_array($mime, self::MUST_CONVERT, true);
        $imageEdits = ImageEdits::fromArray($edits);
        $imageEdits = $imageEdits?->isEmpty() ? null : $imageEdits;
        $animated = $raster && MediaTypes::isAnimated($contents, $mime);

        if ($imageEdits !== null && (! $raster || $animated)) {
            return $this->unchanged($contents, $mime, $width, $height, $animated, 'not_editable');
        }

        if (! $raster || (! $enabled && ! $mustConvert && $imageEdits === null) || $animated) {
            return $this->unchanged($contents, $mime, $width, $height, $animated);
        }

        if ($size === null) {
            return $this->unchanged($contents, $mime, $width, $height, $animated, 'unreadable');
        }

        if (! $this->memory->canDecode($size[0], $size[1])) {
            return $this->unchanged($contents, $mime, $width, $height, $animated, 'too_large');
        }

        $format = $this->targetFormat($mime, $enabled);
        $quality = $this->quality();
        $maxSide = config('media.optimize.max_side');
        $scaled = $enabled && is_int($maxSide) && $maxSide > 0 && ($imageEdits !== null || max($size) > $maxSide);

        $capture = new CaptureThumbnail($this->thumbSide(), $this->quality(), strip: (bool) config('media.optimize.strip_exif'), memory: $this->memory);

        try {
            $image = Image::fromBytes($contents)->orient();

            if ($imageEdits !== null) {
                $image = $imageEdits->apply($image);
            }

            if ($scaled) {
                $image = $image->scale($maxSide, $maxSide);
            }

            $image = $this->capturingThumbnail($image, $capture);
            $image = $format === 'original' ? $image->quality($quality) : $image->optimize($format, $quality);
            $output = ($enabled ? $this->stripped($image) : $image)->toBytes();
        } catch (ImageException) {
            return $this->unchanged($contents, $mime, $width, $height, $animated, 'unreadable');
        }

        $thumbnail = $capture->bytes ?? $this->thumbnail($output);

        if (! $enabled) {
            return $this->converted($output, optimized: false, fingerprint: null, thumbnail: $thumbnail);
        }

        $keepInput = ! $mustConvert
            && $imageEdits === null
            && ! $scaled
            && strlen($output) >= strlen($contents)
            && ! (config('media.optimize.strip_exif') && $this->hasMetadata($contents, $mime));

        if ($keepInput) {
            return new OptimizedImage($contents, $mime, $this->extensionFor($mime), $width, $height, optimized: true, fingerprint: $this->fingerprint(), thumbnail: $thumbnail);
        }

        return $this->converted($output, optimized: true, fingerprint: $this->fingerprint(), mime: $format === 'original' ? $mime : null, thumbnail: $thumbnail);
    }

    /**
     * A WebP preview whose long side is media.thumb_side, or null when the image can't be decoded.
     */
    public function thumbnail(string $contents): ?string
    {
        $size = $this->dimensions($contents, (string) (new finfo(FILEINFO_MIME_TYPE))->buffer($contents));

        if ($size === null || ! $this->memory->canDecode($size[0], $size[1])) {
            return null;
        }

        $side = $this->thumbSide();

        try {
            $image = Image::fromBytes($contents)->orient();

            if (max($size) > $side) {
                $image = $image->scale($side, $side);
            }

            return $this->stripped($image->optimize('webp', $this->quality()))->toBytes();
        } catch (ImageException) {
            return null;
        }
    }

    /**
     * The long side of thumbnails, from media.thumb_side.
     *
     * @return int<1, max>
     */
    private function thumbSide(): int
    {
        return max(1, (int) config('media.thumb_side'));
    }

    /**
     * The settings an optimized image was produced with, e.g. "webp:2560:80:exif0" (exif0 = stripped).
     */
    public function fingerprint(): string
    {
        $maxSide = config('media.optimize.max_side');

        return sprintf(
            '%s:%s:%d:exif%d',
            config('media.optimize.format'),
            is_int($maxSide) && $maxSide > 0 ? $maxSide : 'none',
            config('media.optimize.quality'),
            config('media.optimize.strip_exif') ? 0 : 1,
        );
    }

    /**
     * The encoder format: the format setting; with optimization off, WebP for images that must be converted and
     * the source format for edited ones; and JPEG instead of "original" for a format browsers can't show.
     */
    private function targetFormat(string $mime, bool $enabled): string
    {
        $format = (string) config('media.optimize.format');

        if (! $enabled) {
            return in_array($mime, self::MUST_CONVERT, true) ? 'webp' : 'original';
        }

        return $format === 'original' && in_array($mime, self::MUST_CONVERT, true) ? 'jpg' : $format;
    }

    /**
     * An encoded result; its MIME is sniffed from the bytes unless given.
     */
    private function converted(string $output, bool $optimized, ?string $fingerprint, ?string $mime = null, ?string $thumbnail = null): OptimizedImage
    {
        $outputSize = @getimagesizefromstring($output);
        $outputMime = $mime ?? (string) (new finfo(FILEINFO_MIME_TYPE))->buffer($output);

        return new OptimizedImage(
            $output,
            $outputMime,
            $this->extensionFor($outputMime),
            $outputSize === false ? null : $outputSize[0],
            $outputSize === false ? null : $outputSize[1],
            optimized: $optimized,
            fingerprint: $fingerprint,
            thumbnail: $thumbnail,
        );
    }

    /**
     * @param  'too_large'|'unreadable'|'not_editable'|null  $failure
     */
    private function unchanged(string $contents, string $mime, ?int $width, ?int $height, bool $animated, ?string $failure = null): OptimizedImage
    {
        // Nothing was decoded for the result, so the preview takes its own decode (not for formats browsers can't show).
        $thumbnail = in_array($mime, self::MUST_CONVERT, true) ? null : $this->thumbnail($contents);

        return new OptimizedImage($contents, $mime, $this->extensionFor($mime), $width, $height, optimized: false, failure: $failure, thumbnail: $thumbnail, animated: $animated);
    }

    private function extensionFor(string $mime): string
    {
        return MediaTypes::extensionFor($mime) ?? 'bin';
    }

    /**
     * Width and height from the file header, without decoding the pixels.
     *
     * getimagesize() doesn't read HEIC/HEIF; on Imagick servers those are pinged instead (header only).
     *
     * @return array{0: int<1, max>, 1: int<1, max>}|null
     */
    private function dimensions(string $contents, string $mime): ?array
    {
        $size = @getimagesizefromstring($contents);

        if ($size !== false) {
            return $this->validDimensions($size[0], $size[1]);
        }

        if (! in_array($mime, ['image/heic', 'image/heif'], true) || config('images.default') !== 'imagick' || ! extension_loaded('imagick')) {
            return null;
        }

        try {
            $imagick = new Imagick;
            $imagick->pingImageBlob($contents);

            return $this->validDimensions($imagick->getImageWidth(), $imagick->getImageHeight());
        } catch (ImagickException) {
            return null;
        }
    }

    /**
     * @return array{0: int<1, max>, 1: int<1, max>}|null
     */
    private function validDimensions(int $width, int $height): ?array
    {
        return $width > 0 && $height > 0 ? [$width, $height] : null;
    }

    /**
     * @return int<1, 100>
     */
    private function quality(): int
    {
        return max(1, min(100, (int) config('media.optimize.quality')));
    }

    /**
     * Add the step that encodes the preview from the image as decoded here, after orienting, edits and the max-side scale.
     * Strips metadata from the preview too when strip_exif is on (GD never writes any).
     */
    private function capturingThumbnail(PendingImage $image, CaptureThumbnail $capture): PendingImage
    {
        Image::transformUsing('gd', CaptureThumbnail::class, function (ImageInterface $decoded, CaptureThumbnail $step): ImageInterface {
            $step->capture($decoded, fn (ImageInterface $copy): ImageInterface => $copy);

            return $decoded;
        });
        Image::transformUsing('imagick', CaptureThumbnail::class, function (ImageInterface $decoded, CaptureThumbnail $step): ImageInterface {
            $step->capture($decoded, fn (ImageInterface $copy): ImageInterface => $step->strip ? $copy->modify(new StripMetaModifier) : $copy);

            return $decoded;
        });

        return $image->transform($capture);
    }

    /**
     * Add the metadata strip step when strip_exif is on. GD never writes metadata; Imagick keeps it unless stripped.
     */
    private function stripped(PendingImage $image): PendingImage
    {
        if (! config('media.optimize.strip_exif')) {
            return $image;
        }

        Image::transformUsing('gd', StripMetadata::class, fn (ImageInterface $image): ImageInterface => $image);
        Image::transformUsing('imagick', StripMetadata::class, fn (ImageInterface $image): ImageInterface => $image->modify(new StripMetaModifier));

        return $image->transform(new StripMetadata);
    }

    /**
     * Whether the contents carry an EXIF block: a JPEG APP1 Exif segment, a PNG eXIf chunk or a WebP EXIF chunk.
     */
    private function hasMetadata(string $contents, string $mime): bool
    {
        return match ($mime) {
            'image/jpeg' => preg_match('/\xFF\xE1..Exif\x00\x00/s', $contents) === 1,
            'image/png' => in_array('eXIf', MediaTypes::pngChunks($contents), true),
            'image/webp' => in_array('EXIF', MediaTypes::webpChunks($contents), true),
            default => false,
        };
    }
}

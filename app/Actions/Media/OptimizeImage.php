<?php

namespace App\Actions\Media;

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
     * @param  array<string, mixed>|null  $edits  Reserved for the image editor; ignored for now.
     */
    public function handle(string $contents, string $mime, ?array $edits = null): OptimizedImage
    {
        $raster = in_array($mime, self::RASTER, true);
        $size = $raster ? $this->dimensions($contents, $mime) : null;
        [$width, $height] = $size ?? [null, null];
        $enabled = (bool) config('media.optimize.enabled');
        $mustConvert = in_array($mime, self::MUST_CONVERT, true);

        if (! $raster || (! $enabled && ! $mustConvert) || $this->isAnimated($contents, $mime)) {
            return $this->unchanged($contents, $mime, $width, $height);
        }

        if ($size === null) {
            return $this->unchanged($contents, $mime, $width, $height, 'unreadable');
        }

        if (! $this->memory->canDecode($size[0], $size[1])) {
            return $this->unchanged($contents, $mime, $width, $height, 'too_large');
        }

        $format = $this->targetFormat($mime, $enabled);
        $quality = $this->quality();
        $maxSide = config('media.optimize.max_side');
        $scaled = $enabled && is_int($maxSide) && $maxSide > 0 && max($size) > $maxSide;

        try {
            $image = Image::fromBytes($contents)->orient();

            if ($scaled) {
                $image = $image->scale($maxSide, $maxSide);
            }

            $image = $format === 'original' ? $image->quality($quality) : $image->optimize($format, $quality);
            $output = ($enabled ? $this->stripped($image) : $image)->toBytes();
        } catch (ImageException) {
            return $this->unchanged($contents, $mime, $width, $height, 'unreadable');
        }

        if (! $enabled) {
            return $this->converted($output, optimized: false, fingerprint: null);
        }

        $keepInput = ! $mustConvert
            && ! $scaled
            && strlen($output) >= strlen($contents)
            && ! (config('media.optimize.strip_exif') && $this->hasMetadata($contents, $mime));

        if ($keepInput) {
            return new OptimizedImage($contents, $mime, $this->extensionFor($mime), $width, $height, optimized: true, fingerprint: $this->fingerprint());
        }

        return $this->converted($output, optimized: true, fingerprint: $this->fingerprint(), mime: $format === 'original' ? $mime : null);
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

        $side = max(1, (int) config('media.thumb_side'));

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
     * The encoder format: the format setting, WebP when optimization is off (only images that must be converted
     * get here), and JPEG instead of "original" for a format browsers can't show.
     */
    private function targetFormat(string $mime, bool $enabled): string
    {
        $format = (string) config('media.optimize.format');

        if (! $enabled) {
            return 'webp';
        }

        return $format === 'original' && in_array($mime, self::MUST_CONVERT, true) ? 'jpg' : $format;
    }

    /**
     * An encoded result; its MIME is sniffed from the bytes unless given.
     */
    private function converted(string $output, bool $optimized, ?string $fingerprint, ?string $mime = null): OptimizedImage
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
        );
    }

    /**
     * @param  'too_large'|'unreadable'|null  $failure
     */
    private function unchanged(string $contents, string $mime, ?int $width, ?int $height, ?string $failure = null): OptimizedImage
    {
        return new OptimizedImage($contents, $mime, $this->extensionFor($mime), $width, $height, optimized: false, failure: $failure);
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
            'image/png' => in_array('eXIf', $this->pngChunks($contents), true),
            'image/webp' => in_array('EXIF', $this->webpChunks($contents), true),
            default => false,
        };
    }

    /**
     * Animated GIF (more than one graphic control extension), animated WebP (VP8X animation flag or an ANIM chunk)
     * or APNG (an acTL chunk before the first IDAT). These are never re-encoded, which would keep one frame.
     */
    private function isAnimated(string $contents, string $mime): bool
    {
        return match ($mime) {
            'image/gif' => substr_count($contents, "\x21\xF9\x04") > 1,
            'image/webp' => (substr($contents, 12, 4) === 'VP8X' && (ord($contents[20] ?? "\0") & 0x02) !== 0)
                || in_array('ANIM', $this->webpChunks($contents), true),
            'image/png' => in_array('acTL', $this->pngChunks($contents), true),
            default => false,
        };
    }

    /**
     * Chunk types of a PNG up to the first IDAT (metadata and animation control come before the image data).
     *
     * @return list<string>
     */
    private function pngChunks(string $contents): array
    {
        $types = [];
        $offset = 8;

        while ($offset + 8 <= strlen($contents)) {
            $header = unpack('Nlength', $contents, $offset);

            if ($header === false) {
                break;
            }

            $length = (int) $header['length'];
            $type = substr($contents, $offset + 4, 4);

            if ($type === 'IDAT' || $type === 'IEND') {
                break;
            }

            $types[] = $type;
            $offset += 12 + $length;
        }

        return $types;
    }

    /**
     * Chunk types of a RIFF/WEBP container.
     *
     * @return list<string>
     */
    private function webpChunks(string $contents): array
    {
        if (substr($contents, 0, 4) !== 'RIFF' || substr($contents, 8, 4) !== 'WEBP') {
            return [];
        }

        $types = [];
        $offset = 12;

        while ($offset + 8 <= strlen($contents)) {
            $header = unpack('Vlength', $contents, $offset + 4);

            if ($header === false) {
                break;
            }

            $length = (int) $header['length'];
            $types[] = substr($contents, $offset, 4);
            $offset += 8 + $length + ($length % 2);
        }

        return $types;
    }
}

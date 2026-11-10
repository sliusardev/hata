<?php

namespace App\Actions\Media;

use App\Support\Media\MemoryGuard;
use App\Support\Media\OptimizedImage;
use App\Support\Media\StripMetadata;
use finfo;
use Illuminate\Image\Image as PendingImage;
use Illuminate\Image\ImageException;
use Illuminate\Support\Facades\Image;
use Intervention\Image\Drivers\Imagick\Modifiers\StripMetaModifier;
use Intervention\Image\Interfaces\ImageInterface;

/**
 * Scales, re-encodes and strips raster images according to the media.optimize settings.
 */
class OptimizeImage
{
    /**
     * Raster MIME types the optimizer re-encodes, with the extension used for each.
     *
     * @var array<string, string>
     */
    private const array RASTER = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        'image/gif' => 'gif',
        'image/avif' => 'avif',
        'image/heic' => 'heic',
        'image/heif' => 'heif',
    ];

    public function __construct(private MemoryGuard $memory) {}

    /**
     * @param  array<string, mixed>|null  $edits  Reserved for the image editor; ignored for now.
     */
    public function handle(string $contents, string $mime, ?array $edits = null): OptimizedImage
    {
        $extension = self::RASTER[$mime] ?? null;
        $size = $extension === null ? false : @getimagesizefromstring($contents);
        $width = $size === false ? null : $size[0];
        $height = $size === false ? null : $size[1];

        if ($extension === null || ! config('media.optimize.enabled') || $this->isAnimatedGif($contents, $mime)) {
            return $this->unchanged($contents, $mime, $width, $height);
        }

        if ($size === false || $size[0] < 1 || $size[1] < 1) {
            return $this->unchanged($contents, $mime, $width, $height, 'unreadable');
        }

        if (! $this->memory->canDecode($size[0], $size[1])) {
            return $this->unchanged($contents, $mime, $width, $height, 'too_large');
        }

        $format = (string) config('media.optimize.format');
        $quality = $this->quality();
        $maxSide = config('media.optimize.max_side');

        try {
            $image = Image::fromBytes($contents)->orient();

            if (is_int($maxSide) && $maxSide > 0 && max($width, $height) > $maxSide) {
                $image = $image->scale($maxSide, $maxSide);
            }

            $image = $format === 'original' ? $image->quality($quality) : $image->optimize($format, $quality);
            $output = $this->stripped($image)->toBytes();
        } catch (ImageException) {
            return $this->unchanged($contents, $mime, $width, $height, 'unreadable');
        }

        if (strlen($output) >= strlen($contents) && ! (config('media.optimize.strip_exif') && $this->hasMetadata($contents))) {
            return new OptimizedImage($contents, $mime, $extension, $width, $height, optimized: true, fingerprint: $this->fingerprint());
        }

        $outputSize = @getimagesizefromstring($output);
        $outputMime = $format === 'original' ? $mime : (string) (new finfo(FILEINFO_MIME_TYPE))->buffer($output);

        return new OptimizedImage(
            $output,
            $outputMime,
            self::RASTER[$outputMime] ?? $format,
            $outputSize === false ? null : $outputSize[0],
            $outputSize === false ? null : $outputSize[1],
            optimized: true,
            fingerprint: $this->fingerprint(),
        );
    }

    /**
     * A WebP preview whose long side is media.thumb_side, or null when the image can't be decoded.
     */
    public function thumbnail(string $contents): ?string
    {
        $size = @getimagesizefromstring($contents);

        if ($size === false || $size[0] < 1 || $size[1] < 1 || ! $this->memory->canDecode($size[0], $size[1])) {
            return null;
        }

        $side = max(1, (int) config('media.thumb_side'));

        try {
            $image = Image::fromBytes($contents)->orient();

            if (max($size[0], $size[1]) > $side) {
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
     * @param  'too_large'|'unreadable'|null  $failure
     */
    private function unchanged(string $contents, string $mime, ?int $width, ?int $height, ?string $failure = null): OptimizedImage
    {
        return new OptimizedImage($contents, $mime, self::RASTER[$mime] ?? '', $width, $height, optimized: false, failure: $failure);
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
     * Whether the contents carry an EXIF block (JPEG APP1, PNG eXIf chunk, WebP EXIF chunk, HEIF/AVIF Exif item).
     */
    private function hasMetadata(string $contents): bool
    {
        return str_contains($contents, 'eXIf') || str_contains($contents, 'EXIF') || str_contains($contents, 'Exif');
    }

    /**
     * A GIF with more than one graphic control extension block has several frames.
     */
    private function isAnimatedGif(string $contents, string $mime): bool
    {
        return $mime === 'image/gif' && substr_count($contents, "\x21\xF9\x04") > 1;
    }
}

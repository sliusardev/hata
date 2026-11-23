<?php

namespace App\Support\Media;

use Illuminate\Image\Image as PendingImage;
use Illuminate\Support\Facades\Image;
use Intervention\Image\Interfaces\ImageInterface;
use InvalidArgumentException;

/**
 * The user's non-destructive edits of an image: rotate, flip, crop and a maximum output size.
 *
 * The crop is in the pixels of the image after rotating and flipping (the frame the user sees).
 */
final readonly class ImageEdits
{
    /**
     * @param  array{x: int, y: int, w: int, h: int}|null  $crop
     * @param  array{w: int<1, max>, h: int<1, max>}|null  $size  Largest output size; the image is scaled down to fit, never up.
     */
    public function __construct(
        public int $rotate = 0,
        public bool $flipH = false,
        public bool $flipV = false,
        public ?array $crop = null,
        public ?array $size = null,
    ) {}

    /**
     * @param  array<string, mixed>|null  $edits  The stored JSON shape (snake_case keys).
     *
     * @throws InvalidArgumentException
     */
    public static function fromArray(?array $edits): ?self
    {
        if ($edits === null || $edits === []) {
            return null;
        }

        $rotate = $edits['rotate'] ?? 0;

        if (! in_array($rotate, [0, 90, 180, 270], true)) {
            throw new InvalidArgumentException('The rotation must be 0, 90, 180 or 270.');
        }

        $crop = $edits['crop'] ?? null;
        $size = $edits['size'] ?? null;

        return new self(
            $rotate,
            (bool) ($edits['flip_h'] ?? false),
            (bool) ($edits['flip_v'] ?? false),
            $crop === null ? null : self::cropFrom($crop),
            $size === null ? null : self::sizeFrom($size),
        );
    }

    /**
     * @return array{x: int, y: int, w: int, h: int}
     */
    private static function cropFrom(mixed $crop): array
    {
        if (! is_array($crop)) {
            throw new InvalidArgumentException('The crop must be an array.');
        }

        $values = [];

        foreach (['x', 'y', 'w', 'h'] as $key) {
            $value = $crop[$key] ?? null;

            if (! is_int($value) || $value < 0) {
                throw new InvalidArgumentException("The crop {$key} must be a non-negative integer.");
            }

            $values[$key] = $value;
        }

        if ($values['w'] < 1 || $values['h'] < 1) {
            throw new InvalidArgumentException('The crop width and height must be positive.');
        }

        return ['x' => $values['x'], 'y' => $values['y'], 'w' => $values['w'], 'h' => $values['h']];
    }

    /**
     * @return array{w: int<1, max>, h: int<1, max>}
     */
    private static function sizeFrom(mixed $size): array
    {
        $w = is_array($size) ? ($size['w'] ?? null) : null;
        $h = is_array($size) ? ($size['h'] ?? null) : null;

        if (! is_int($w) || ! is_int($h) || $w < 1 || $h < 1) {
            throw new InvalidArgumentException('The size width and height must be positive integers.');
        }

        return ['w' => $w, 'h' => $h];
    }

    /**
     * @return array{rotate: int, flip_h: bool, flip_v: bool, crop: array{x: int, y: int, w: int, h: int}|null, size: array{w: int<1, max>, h: int<1, max>}|null}
     */
    public function toArray(): array
    {
        return [
            'rotate' => $this->rotate,
            'flip_h' => $this->flipH,
            'flip_v' => $this->flipV,
            'crop' => $this->crop,
            'size' => $this->size,
        ];
    }

    public function isEmpty(): bool
    {
        return $this->rotate === 0 && ! $this->flipH && ! $this->flipV && $this->crop === null && $this->size === null;
    }

    /**
     * Add the edits to the pipeline: rotate (clockwise), flip horizontally then vertically, crop (clamped to the
     * image), then scale down to fit the size.
     */
    public function apply(PendingImage $image): PendingImage
    {
        if ($this->rotate !== 0) {
            $image = $image->rotate($this->rotate);
        }

        if ($this->flipH) {
            $image = $image->flipHorizontally();
        }

        if ($this->flipV) {
            $image = $image->flipVertically();
        }

        if ($this->crop !== null) {
            $this->registerCropHandlers();
            $image = $image->transform(new ClampedCrop($this->crop['x'], $this->crop['y'], $this->crop['w'], $this->crop['h']));
        }

        if ($this->size !== null) {
            $image = $image->scale($this->size['w'], $this->size['h']);
        }

        return $image;
    }

    /**
     * The size the edits produce from a source of the given size (before any max-side scaling).
     *
     * @return array{int, int}
     */
    public function outputSize(int $sourceWidth, int $sourceHeight): array
    {
        [$width, $height] = $this->rotate % 180 === 90 ? [$sourceHeight, $sourceWidth] : [$sourceWidth, $sourceHeight];

        if ($this->crop !== null) {
            $x = min($this->crop['x'], $width - 1);
            $y = min($this->crop['y'], $height - 1);
            $width = min($this->crop['w'], $width - $x);
            $height = min($this->crop['h'], $height - $y);
        }

        if ($this->size !== null) {
            $factor = min(1, $this->size['w'] / $width, $this->size['h'] / $height);
            $width = max(1, (int) round($width * $factor));
            $height = max(1, (int) round($height * $factor));
        }

        return [max(1, $width), max(1, $height)];
    }

    private function registerCropHandlers(): void
    {
        $handler = static function (ImageInterface $image, ClampedCrop $crop): ImageInterface {
            $x = max(0, min($crop->x, $image->width() - 1));
            $y = max(0, min($crop->y, $image->height() - 1));

            return $image->crop(
                max(1, min($crop->width, $image->width() - $x)),
                max(1, min($crop->height, $image->height() - $y)),
                $x,
                $y,
            );
        };

        Image::transformUsing('gd', ClampedCrop::class, $handler);
        Image::transformUsing('imagick', ClampedCrop::class, $handler);
    }
}

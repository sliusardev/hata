<?php

namespace Hata\Support\Media;

use Illuminate\Contracts\Image\Transformation;
use Intervention\Image\Encoders\WebpEncoder;
use Intervention\Image\Exceptions\ImageException;
use Intervention\Image\Interfaces\ImageInterface;

/**
 * Image transformation that encodes a WebP preview from the image as decoded at this point of the pipeline,
 * so the thumbnail costs no second decode. The image itself passes through untouched; the bytes land in $bytes.
 */
class CaptureThumbnail implements Transformation
{
    public ?string $bytes = null;

    public function __construct(
        public readonly int $side,
        public readonly int $quality,
        public readonly bool $strip,
        private readonly MemoryGuard $memory,
    ) {}

    /**
     * Encode a copy of the image scaled down to the long side. $bytes stays null when the copy wouldn't fit in the remaining
     * memory (checked before cloning, as exhaustion is fatal) or can't be encoded, and the caller then makes the thumbnail
     * from the result instead. Other exceptions propagate.
     *
     * @param  callable(ImageInterface): ImageInterface  $prepare  Driver-specific step applied to the copy, e.g. stripping metadata.
     */
    public function capture(ImageInterface $image, callable $prepare): void
    {
        if (! $this->memory->canDecode($image->width(), $image->height())) {
            return;
        }

        try {
            $copy = $prepare((clone $image)->scaleDown($this->side, $this->side));
            $this->bytes = $copy->encode(new WebpEncoder($this->quality))->toString();
        } catch (ImageException) {
            $this->bytes = null;
        }
    }
}

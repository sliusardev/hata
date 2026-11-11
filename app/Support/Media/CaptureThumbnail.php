<?php

namespace App\Support\Media;

use Illuminate\Contracts\Image\Transformation;
use Intervention\Image\Encoders\WebpEncoder;
use Intervention\Image\Interfaces\ImageInterface;
use Throwable;

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
    ) {}

    /**
     * Encode a copy of the image scaled down to the long side; a failure leaves $bytes null instead of failing the main output.
     *
     * @param  callable(ImageInterface): ImageInterface  $prepare  Driver-specific step applied to the copy, e.g. stripping metadata.
     */
    public function capture(ImageInterface $image, callable $prepare): void
    {
        try {
            $copy = $prepare((clone $image)->scaleDown($this->side, $this->side));
            $this->bytes = $copy->encode(new WebpEncoder($this->quality))->toString();
        } catch (Throwable) {
            $this->bytes = null;
        }
    }
}

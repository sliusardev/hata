<?php

namespace Hata\Support\Media;

use Illuminate\Contracts\Image\Transformation;

/**
 * Image transformation that crops a rectangle clamped to the bounds of the image being processed,
 * which the pipeline only knows at run time (after orienting and rotating).
 */
class ClampedCrop implements Transformation
{
    public function __construct(
        public readonly int $x,
        public readonly int $y,
        public readonly int $width,
        public readonly int $height,
    ) {}
}

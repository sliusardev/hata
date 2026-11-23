<?php

namespace Hata\Support\Media;

use Illuminate\Contracts\Image\Transformation;

/**
 * Image transformation that removes EXIF and other metadata (the ICC profile is kept).
 */
class StripMetadata implements Transformation
{
    //
}

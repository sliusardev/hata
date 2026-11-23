<?php

namespace Hata\Exceptions;

use RuntimeException;

/**
 * Thrown when a disk reports that it did not store a media object (a cloud disk configured with `'throw' => false`
 * returns false instead of throwing), or when an SVG is about to be written to a disk that publishes its files. The
 * callers roll back what they wrote, so no row points at a missing object.
 */
class MediaWriteException extends RuntimeException
{
    public static function notWritten(string $disk, string $path): self
    {
        return new self("The [{$disk}] disk did not store [{$path}].");
    }

    public static function svgOnPublishingDisk(string $disk, string $path): self
    {
        return new self("The [{$disk}] disk serves its files publicly, so it must not store the SVG [{$path}].");
    }
}

<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown when a disk reports that it did not store a media object (a cloud disk configured with `'throw' => false`
 * returns false instead of throwing). The callers roll back what they wrote, so no row points at a missing object.
 */
class MediaWriteException extends RuntimeException
{
    public static function notWritten(string $disk, string $path): self
    {
        return new self("The [{$disk}] disk did not store [{$path}].");
    }
}

<?php

namespace App\Support\Media;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Image;
use Throwable;

/**
 * What this server can do for the media library. Bound in the container, so tests can replace it.
 */
class ServerCapabilities
{
    /**
     * Largest accepted upload in bytes: the smaller of upload_max_filesize and post_max_size.
     * Null when PHP sets no limit (a value of 0).
     */
    public function uploadCapBytes(): ?int
    {
        $limits = array_filter([
            ini_parse_quantity((string) ini_get('upload_max_filesize')),
            ini_parse_quantity((string) ini_get('post_max_size')),
        ], fn (int $bytes): bool => $bytes > 0);

        return $limits === [] ? null : min($limits);
    }

    /**
     * Whether the image driver can encode AVIF. Checked once by encoding a 1x1 image.
     */
    public function supportsAvif(): bool
    {
        return Cache::remember('media.avif_supported', now()->addDay(), function (): bool {
            try {
                $pixel = (string) base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR4nGP4z8DwHwAFAAH/q842iQAAAABJRU5ErkJggg==');

                return Image::fromBytes($pixel)->toAvif()->toBytes() !== '';
            } catch (Throwable) {
                return false;
            }
        });
    }
}

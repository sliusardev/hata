<?php

namespace App\Support\Media;

/**
 * Estimates whether decoding an image of the given size fits in the remaining PHP memory.
 */
class MemoryGuard
{
    /**
     * Bytes per pixel (RGBA) times the overhead of the decoded copy and the working copy.
     */
    private const float BYTES_PER_PIXEL = 4 * 2.2;

    /**
     * @param  int|null  $limitBytes  Memory limit in bytes; null reads memory_limit, -1 means unlimited.
     */
    public function __construct(private ?int $limitBytes = null) {}

    public function canDecode(int $width, int $height): bool
    {
        $limit = $this->limitBytes ?? ini_parse_quantity((string) ini_get('memory_limit'));

        if ($limit < 0) {
            return true;
        }

        return $width * $height * self::BYTES_PER_PIXEL <= $limit - memory_get_usage(true);
    }
}

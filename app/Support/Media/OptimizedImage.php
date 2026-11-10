<?php

namespace App\Support\Media;

/**
 * The result of optimizing an uploaded image.
 */
final readonly class OptimizedImage
{
    /**
     * @param  bool  $optimized  Whether the contents went through (or already match) the current settings.
     * @param  string|null  $fingerprint  The settings the image was optimized with; null when not optimized.
     * @param  'too_large'|'unreadable'|'not_editable'|null  $failure
     */
    public function __construct(
        public string $contents,
        public string $mime,
        public string $extension,
        public ?int $width,
        public ?int $height,
        public bool $optimized,
        public ?string $fingerprint = null,
        public ?string $failure = null,
    ) {}
}

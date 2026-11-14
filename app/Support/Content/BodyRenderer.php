<?php

namespace App\Support\Content;

use App\Models\Media;
use Illuminate\Support\HtmlString;

/**
 * Prepares a stored body for the page. The HTML was sanitized on save and is not sanitized again; only the image
 * nodes change: each gets its file's current address, size and lazy loading, and an image whose file is gone
 * (or is no longer an image) is dropped.
 */
class BodyRenderer
{
    public function __construct(private BodySanitizer $sanitizer = new BodySanitizer) {}

    public function render(?string $html): HtmlString
    {
        if ($html === null || ! str_contains($html, '<img')) {
            return new HtmlString((string) $html);
        }

        $document = BodySanitizer::load($html);
        $images = BodySanitizer::images($document);

        $files = $images === []
            ? collect()
            : Media::query()->whereIn('id', $this->sanitizer->mediaIds($html))->get()->keyBy('id');

        foreach ($images as $image) {
            $file = $files->get((int) $image->getAttribute('data-media-id'));

            if (! $file instanceof Media || ! $file->isImage()) {
                $image->remove();

                continue;
            }

            $alt = trim($image->getAttribute('alt') ?? '');

            $image->removeAttribute('data-media-id');
            $image->setAttribute('src', $file->url());

            if ($file->width !== null && $file->height !== null) {
                $image->setAttribute('width', (string) $file->width);
                $image->setAttribute('height', (string) $file->height);
            }

            $image->setAttribute('alt', $alt !== '' ? $alt : $file->altFor(app()->getLocale()));
            $image->setAttribute('loading', 'lazy');
            $image->setAttribute('decoding', 'async');
        }

        return new HtmlString((string) BodySanitizer::html($document));
    }
}

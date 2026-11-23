<?php

namespace Hata\Support\Assets;

use Hata\Hata;
use Illuminate\Foundation\Vite;
use Illuminate\Support\HtmlString;

/**
 * Renders the tags of Hata's built admin and site assets (`dist/`, published to `public/vendor/hata`).
 *
 * It always uses a Vite instance of its own, so the host's `@vite`, its build directory and its manifest are never
 * touched, and the package dev server (`npm run dev` in the package, which writes `hot` in the package root) works
 * without the host knowing.
 */
final class HataAssets
{
    /**
     * The published directory, relative to the host's public directory (the Vite base is `/vendor/hata/`).
     */
    public const string DIRECTORY = 'vendor/hata';

    /**
     * The `<link>` and `<script>` tags of the given entries (paths as in `vite.config.js`).
     *
     * @param  list<string>  $entries
     */
    public static function tags(array $entries): HtmlString
    {
        return self::vite()($entries);
    }

    /**
     * The `@font-face` rules and preloads of the bundled font, from the published files: no request leaves the site.
     */
    public static function fonts(): HtmlString
    {
        return self::vite()->fonts();
    }

    /**
     * The address of a static file of the build (the favicons), under the host's own asset URL.
     */
    public static function url(string $path): string
    {
        return asset(self::DIRECTORY.'/'.ltrim($path, '/'));
    }

    private static function vite(): Vite
    {
        return (new HataVite)
            ->useBuildDirectory(self::DIRECTORY)
            ->useManifestFilename('.vite/manifest.json')
            ->useHotFile(Hata::path('hot'));
    }
}

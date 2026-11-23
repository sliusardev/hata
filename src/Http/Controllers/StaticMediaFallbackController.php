<?php

namespace Hata\Http\Controllers;

use Hata\Http\Controllers\Concerns\StreamsMediaFile;
use Hata\Models\Media;
use Hata\Support\Media\MediaDisks;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\Response;

class StaticMediaFallbackController extends Controller
{
    use StreamsMediaFile;

    /**
     * A stored name on the public disk: `{key}-v{version}.{ext}`, the key an uppercase ULID.
     */
    private const string NAME = '/^([0-9A-HJKMNP-TV-Z]{26})-v([1-9][0-9]{0,9})\.[A-Za-z0-9]+$/';

    /**
     * Answer `/storage/media/{path}` for a file or thumbnail of a row on the `public` disk, when the web server did
     * not (the `public/storage` link is missing). The row is found by the ULID in the file name (one query on the
     * unique `key` index); only a raster image that is not SVG qualifies, and the requested path must equal the row's
     * `path` or `thumb_path` exactly, which is then streamed. Its name carries the version, so it may be cached for a
     * week. An older version of the same key (a page cached before a crop) gets an uncacheable 302 to the current file
     * or thumbnail on this same route, never through `media.show`, which could send it back to the broken link.
     * Anything else is 404, and the private disk is never read.
     */
    public function __invoke(Request $request, string $path): Response
    {
        abort_if(preg_match(self::NAME, basename($path), $name) !== 1, 404);

        $stored = 'media/'.$path;
        $media = Media::query()->where('key', $name[1])->first();

        abort_if(
            $media === null
                || $media->key !== $name[1]
                || $media->disk !== MediaDisks::PUBLIC
                || ! MediaDisks::isRasterImage($media->kind, (string) $media->mime),
            404,
        );

        $thumb = str_starts_with($path, 'thumbs/');

        if ($stored !== ($thumb ? $media->thumb_path : $media->path)) {
            return $this->currentVersion($media, $thumb, (int) $name[2]);
        }

        return $this->streamFile(
            $request,
            $media,
            $stored,
            $thumb ? 'image/webp' : (string) $media->mime,
            HeaderUtils::makeDisposition('inline', basename($stored), 'file'),
            'public, max-age=604800',
        );
    }

    /**
     * Redirect a request for an older version to the row's current file or thumbnail; 404 for anything that is not
     * older (a wrong extension or directory of the current version, a version that does not exist yet), or a thumbnail
     * the row no longer has.
     */
    private function currentVersion(Media $media, bool $thumb, int $requested): RedirectResponse
    {
        $current = $thumb ? $media->thumb_path : $media->path;

        abort_if($current === null || $requested >= (int) $media->version, 404);

        return redirect()->route('media.static', ['path' => substr($current, strlen('media/'))], 302)->withHeaders([
            'Cache-Control' => 'no-cache, must-revalidate, max-age=0',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}

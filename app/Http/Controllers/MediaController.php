<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\StreamsMediaFile;
use App\Models\Media;
use App\Support\Media\MediaDisks;
use App\Support\Media\MediaTypes;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\Response;

class MediaController extends Controller
{
    use StreamsMediaFile;

    /**
     * Serve a media file by its public URL. The name segment is cosmetic: an old name
     * redirects to the current one, so URLs saved in content keep working after a rename.
     */
    public function __invoke(Request $request, string $key, string $name): Response
    {
        $media = Media::query()->where('key', Str::upper($key))->firstOrFail();
        $thumb = $request->boolean('thumb');

        abort_if($thumb && $media->thumb_path === null, 404);

        if ($name !== $media->name) {
            return $this->uncacheableRedirect($this->currentNameUrl($request, $media, $thumb), 301);
        }

        $path = $thumb ? (string) $media->thumb_path : $media->path;
        $mime = $thumb ? 'image/webp' : $media->mime;
        $download = $request->boolean('download');
        $disk = Storage::disk($media->disk);
        $disposition = HeaderUtils::makeDisposition(
            $download ? 'attachment' : ($thumb ? 'inline' : MediaTypes::disposition($mime)),
            $media->name,
            $this->asciiFallback($media->name),
        );

        if (! $download && ! MediaDisks::isCloud($media->disk)) {
            $direct = $media->directUrl($path, $mime);

            if ($direct !== null) {
                return $this->uncacheableRedirect($direct, 302);
            }
        }

        if (MediaDisks::isCloud($media->disk)) {
            if ($mime !== 'image/svg+xml') {
                return $this->uncacheableRedirect($this->cloudUrl($media, $path, $mime, $download, $disposition), 302);
            }
        } else {
            abort_unless($disk->exists($path), 404);
        }

        return $this->streamFile(
            $request,
            $media,
            $path,
            $mime,
            $disposition,
            $request->query('v') === (string) $media->version ? 'public, max-age=604800' : 'no-cache',
        );
    }

    /**
     * Where a cloud file is fetched from: the public/CDN URL for a raster image on a disk that has one (not for a
     * download, which needs the real name), otherwise a signed URL whose disposition and type carry the real name.
     *
     * The signature is made for the start of the current clock hour and expires two hours after it (so at least an
     * hour from now): every request within the hour gets the same URL, and the browser can reuse the object it cached
     * instead of fetching a new URL each time. The SDK reads `start_time` as a presign option (Laravel passes the
     * options to the command too, which ignores unknown keys).
     */
    private function cloudUrl(Media $media, string $path, string $mime, bool $download, string $disposition): string
    {
        $direct = $download ? null : $media->directUrl($path, $mime);

        if ($direct !== null) {
            return $direct;
        }

        $signedAt = now()->startOfHour();

        return Storage::disk($media->disk)->temporaryUrl($path, $signedAt->addHours(2), [
            'ResponseContentDisposition' => $disposition,
            'ResponseContentType' => MediaTypes::responseMime($mime),
            'start_time' => $signedAt->getTimestamp(),
        ]);
    }

    private function asciiFallback(string $name): string
    {
        return str_replace(['%', '/', '\\'], '-', Str::ascii($name));
    }

    /**
     * The URL under the current name, keeping the request's version, thumb and download flags.
     */
    private function currentNameUrl(Request $request, Media $media, bool $thumb): string
    {
        $version = $request->query('v');
        $parameters = [
            ...($thumb ? ['thumb' => 1] : []),
            ...($request->boolean('download') ? ['download' => 1] : []),
            'v' => is_string($version) && $version !== '' ? $version : $media->version,
        ];

        return route('media.show', [$media->key, $media->name, ...$parameters]);
    }

    /**
     * Redirects must never be cached: a rename A to B and back to A would otherwise
     * leave browsers in a redirect loop. Signed URLs are short-lived, so they must not be cached either.
     */
    private function uncacheableRedirect(string $url, int $status): RedirectResponse
    {
        return redirect()->away($url, $status)->withHeaders([
            'Cache-Control' => 'no-cache, must-revalidate, max-age=0',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}

<?php

namespace App\Http\Controllers;

use App\Models\Media;
use App\Support\Media\ByteRange;
use App\Support\Media\MediaDisks;
use App\Support\Media\MediaTypes;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use League\Flysystem\UnableToReadFile;
use League\Flysystem\UnableToRetrieveMetadata;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class MediaController extends Controller
{
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

        if (MediaDisks::isCloud($media->disk)) {
            if ($mime !== 'image/svg+xml') {
                return $this->uncacheableRedirect($this->cloudUrl($media, $path, $mime, $download, $disposition), 302);
            }
        } else {
            abort_unless($disk->exists($path), 404);
        }

        $headers = [
            'Content-Type' => MediaTypes::responseMime($mime),
            'Cache-Control' => $request->query('v') === (string) $media->version
                ? 'public, max-age=604800'
                : 'no-cache',
            'X-Content-Type-Options' => 'nosniff',
            'Accept-Ranges' => 'bytes',
            'Content-Disposition' => $disposition,
        ];

        if ($mime === 'image/svg+xml') {
            $headers['Content-Security-Policy'] = "default-src 'none'; style-src 'unsafe-inline'; sandbox";
        }

        $etag = md5($path.$media->size.$media->version.$media->updated_at?->getTimestamp());
        $notModified = new StreamedResponse(null, 200, $headers);
        $notModified->setEtag($etag);
        $notModified->setLastModified($media->updated_at);

        if ($notModified->isNotModified($request)) {
            return $notModified;
        }

        try {
            $size = $disk->size($path);
            $stream = $request->isMethod('HEAD') ? null : $disk->readStream($path);
        } catch (UnableToReadFile|UnableToRetrieveMetadata) {
            abort(404);
        }

        abort_if($stream === false, 404);

        $range = ByteRange::parse($request->headers->get('Range'), $request->headers->get('If-Range'), $size, $etag);

        if ($range === false) {
            return new Response('', 416, [
                ...$headers,
                'Cache-Control' => 'no-store',
                'Content-Range' => "bytes */{$size}",
            ]);
        }

        [$start, $end] = $range ?? [0, $size - 1];
        $status = $range === null ? 200 : 206;
        $headers['Content-Length'] = (string) max(0, $end - $start + 1);

        if ($status === 206) {
            $headers['Content-Range'] = "bytes {$start}-{$end}/{$size}";
            $headers['Cache-Control'] = 'no-store';
        }

        $response = new StreamedResponse(function () use ($stream, $start, $end, $size): void {
            if ($stream === null) {
                return;
            }

            if ($size > 0) {
                ByteRange::copy($stream, $start, $end);
            }

            fclose($stream);
        }, $status, $headers);

        $response->setEtag($etag);
        $response->setLastModified($media->updated_at);

        return $response;
    }

    /**
     * Where a cloud file is fetched from: the public/CDN URL for a raster image on a disk that has one (not for a
     * download, which needs the real name), otherwise a signed URL (valid for an hour) whose disposition and type
     * carry the real name.
     */
    private function cloudUrl(Media $media, string $path, string $mime, bool $download, string $disposition): string
    {
        $direct = $download ? null : $media->directUrl($path, $mime);

        if ($direct !== null) {
            return $direct;
        }

        return Storage::disk($media->disk)->temporaryUrl($path, now()->addHour(), [
            'ResponseContentDisposition' => $disposition,
            'ResponseContentType' => MediaTypes::responseMime($mime),
        ]);
    }

    private function asciiFallback(string $name): string
    {
        return str_replace(['%', '/', '\\'], '-', Str::ascii($name));
    }

    /**
     * The URL under the current name, keeping the request's version and thumb flag.
     */
    private function currentNameUrl(Request $request, Media $media, bool $thumb): string
    {
        $version = $request->query('v');
        $parameters = [
            ...($thumb ? ['thumb' => 1] : []),
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

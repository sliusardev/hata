<?php

namespace App\Http\Controllers\Concerns;

use App\Models\Media;
use App\Support\Media\ByteRange;
use App\Support\Media\MediaTypes;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\UnableToReadFile;
use League\Flysystem\UnableToRetrieveMetadata;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

trait StreamsMediaFile
{
    /**
     * Stream one stored object of a media row from its disk: conditional (ETag, Last-Modified), HEAD and byte-range
     * requests, `nosniff`, and the sandbox policy for SVG. The caller decides `$path`, `$mime`, the disposition and
     * the cache policy.
     */
    protected function streamFile(Request $request, Media $media, string $path, string $mime, string $disposition, string $cacheControl): Response
    {
        $disk = Storage::disk($media->disk);

        $headers = [
            'Content-Type' => MediaTypes::responseMime($mime),
            'Cache-Control' => $cacheControl,
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

        abort_if($stream === null && ! $request->isMethod('HEAD'), 404);

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
}

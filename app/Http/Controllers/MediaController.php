<?php

namespace App\Http\Controllers;

use App\Models\Media;
use App\Support\Media\MediaTypes;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

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
            return $this->uncacheableRedirect($thumb ? (string) $media->thumbUrl() : $media->url(), 301);
        }

        $path = $thumb ? (string) $media->thumb_path : $media->path;

        if (config("filesystems.disks.{$media->disk}.driver") !== 'local') {
            return $this->uncacheableRedirect(Storage::disk($media->disk)->url($path), 302);
        }

        $disk = Storage::disk($media->disk);

        abort_unless($disk->exists($path), 404);

        $mime = $thumb ? 'image/webp' : $media->mime;
        $headers = [
            'Content-Type' => MediaTypes::responseMime($mime),
            'Cache-Control' => 'public, max-age=604800',
            'X-Content-Type-Options' => 'nosniff',
        ];

        if ($mime === 'image/svg+xml') {
            $headers['Content-Security-Policy'] = "default-src 'none'; style-src 'unsafe-inline'; sandbox";
        }

        $response = $disk->response($path, $media->name, $headers, $thumb ? 'inline' : MediaTypes::disposition($mime));

        $response->setEtag(md5($path.$media->size.$media->updated_at?->getTimestamp()));
        $response->setLastModified($media->updated_at);
        $response->isNotModified($request);

        return $response;
    }

    /**
     * Redirects must never be cached: a rename A to B and back to A would otherwise
     * leave browsers in a redirect loop. Cloud disks (any non-local driver) use this
     * too; Part 5 completes that branch.
     */
    private function uncacheableRedirect(string $url, int $status): RedirectResponse
    {
        return redirect()->away($url, $status)->withHeaders([
            'Cache-Control' => 'no-cache, must-revalidate, max-age=0',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}

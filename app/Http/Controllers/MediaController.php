<?php

namespace App\Http\Controllers;

use App\Models\Media;
use App\Support\Media\MediaTypes;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

class MediaController extends Controller
{
    /**
     * Serve a media file by its public URL. The name segment is cosmetic: an old name
     * redirects to the current one, so URLs saved in content keep working after a rename.
     */
    public function __invoke(Request $request, string $key, string $name): Response
    {
        $media = Media::query()->where('key', $key)->firstOrFail();
        $thumb = $request->boolean('thumb');

        if ($name !== $media->name) {
            return redirect($thumb ? ($media->thumbUrl() ?? $media->url()) : $media->url(), 301)
                ->withHeaders(['X-Content-Type-Options' => 'nosniff']);
        }

        $path = $thumb ? $media->thumb_path : $media->path;

        abort_if($path === null, 404);

        $disk = Storage::disk($media->disk);

        if ($media->disk !== 'local') {
            return $this->redirectToDisk($media->disk, $path);
        }

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
     * Cloud disks serve the file themselves; this is the stub that Part 5 completes.
     */
    private function redirectToDisk(string $diskName, string $path): RedirectResponse
    {
        return redirect()->away(Storage::disk($diskName)->url($path))
            ->withHeaders(['X-Content-Type-Options' => 'nosniff']);
    }
}

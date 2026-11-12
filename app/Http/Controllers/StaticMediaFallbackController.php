<?php

namespace App\Http\Controllers;

use App\Enums\MediaKind;
use App\Http\Controllers\Concerns\StreamsMediaFile;
use App\Models\Media;
use App\Support\Media\MediaDisks;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\Response;

class StaticMediaFallbackController extends Controller
{
    use StreamsMediaFile;

    /**
     * Answer `/storage/media/{path}` for a file that a row on the `public` disk references as its file or thumbnail.
     * The path carries the file's version, so whatever is found is current and may be cached for a week. Nothing
     * else is served: no other path on the disk, no SVG, never the private disk.
     */
    public function __invoke(Request $request, string $path): Response
    {
        abort_if(preg_match('#(^|/)\.\.(/|$)#', $path) === 1, 404);

        $stored = 'media/'.$path;

        $media = Media::query()
            ->where('disk', MediaDisks::PUBLIC)
            ->where('kind', MediaKind::Image)
            ->where(fn ($query) => $query->where('path', $stored)->orWhere('thumb_path', $stored))
            ->first();

        abort_if($media === null || $media->mime === 'image/svg+xml', 404);

        $thumb = $media->thumb_path === $stored && $media->path !== $stored;
        $mime = $thumb ? 'image/webp' : $media->mime;

        return $this->streamFile(
            $request,
            $media,
            $stored,
            $mime,
            HeaderUtils::makeDisposition('inline', basename($stored), 'file'),
            'public, max-age=604800',
        );
    }
}

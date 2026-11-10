<?php

namespace App\Http\Controllers;

use App\Actions\Media\ZipMedia;
use App\Models\Media;
use App\Models\User;
use App\Support\Media\MediaIds;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class MediaZipController extends Controller
{
    /**
     * Download the files given as ids[] in one ZIP archive. The archive is built in the
     * system temp directory and deleted once it is sent.
     */
    public function __invoke(Request $request, ZipMedia $zipMedia): BinaryFileResponse
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 403);

        $ids = $request->query('ids');
        $ids = MediaIds::existing(is_array($ids) ? $ids : []);

        abort_if($ids === [], 404);
        abort_if(count($ids) > ZipMedia::MAX_FILES, 422);

        $path = $zipMedia->handle($actor, Media::query()->whereKey($ids)->orderBy('name')->get());

        abort_if($path === null, 404);

        return response()->download($path, 'media.zip', ['Content-Type' => 'application/zip'])->deleteFileAfterSend();
    }
}

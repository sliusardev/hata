<?php

namespace Hata\Http\Controllers;

use Hata\Actions\Media\ZipMedia;
use Hata\Contracts\AdminUser;
use Hata\Models\Media;
use Hata\Support\Media\MediaIds;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use ZipArchive;

class MediaZipController extends Controller
{
    /**
     * Download the files given as ids[] in one ZIP archive. The archive is built in the
     * system temp directory and deleted once it is sent.
     */
    public function __invoke(Request $request, ZipMedia $zipMedia): BinaryFileResponse
    {
        $actor = $request->user();
        abort_unless($actor instanceof AdminUser, 403);

        // Without the zip extension there is no archive to build. class_exists() can't be faked,
        // so this branch is only exercised on a PHP without zip (see MediaZipTest).
        abort_unless(class_exists(ZipArchive::class), 404);

        $ids = $request->query('ids');
        $ids = MediaIds::existing(is_array($ids) ? $ids : []);

        abort_if($ids === [], 404);
        abort_if(count($ids) > ZipMedia::MAX_FILES, 422);

        $path = $zipMedia->handle($actor, Media::query()->whereKey($ids)->orderBy('name')->get());

        abort_if($path === null, 404);

        return response()->download($path, 'media.zip', ['Content-Type' => 'application/zip'])->deleteFileAfterSend();
    }
}

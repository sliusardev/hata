<?php

namespace App\Actions\Media;

use App\Enums\MediaKind;
use App\Exceptions\MediaUploadException;
use App\Exceptions\MediaWriteException;
use App\Models\Media;
use App\Models\MediaFolder;
use App\Models\User;
use App\Support\Media\MediaDisks;
use App\Support\Media\MediaNames;
use App\Support\Media\MediaPaths;
use App\Support\Media\MediaUploadChecks;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

class UploadMedia
{
    public function __construct(private OptimizeImage $optimizer) {}

    /**
     * @param  int|null  $pickedSize  Size of the file the user picked, when the browser shrank it before upload.
     * @param  string|null  $pickedExtension  Extension of the file the user picked, when the browser converted it (e.g. HEIC to JPEG).
     *                                        It comes from the client: used only when it is on the whitelist.
     *
     * @throws MediaUploadException
     * @throws AuthorizationException
     */
    public function handle(User $actor, UploadedFile $file, ?MediaFolder $folder = null, ?int $pickedSize = null, ?string $pickedExtension = null): Media
    {
        Gate::forUser($actor)->authorize('create', Media::class);

        return $this->store($actor->id, $file, $folder, $pickedSize, $pickedExtension);
    }

    /**
     * Store a file without an actor (`uploaded_by` stays null), with the same checks, optimization and storing as `handle()`.
     * Only for code that runs outside a request, such as the migration that imports the old logos.
     *
     * @throws MediaUploadException
     */
    public function import(UploadedFile $file, ?MediaFolder $folder = null): Media
    {
        return $this->store(null, $file, $folder, null, null);
    }

    /**
     * @throws MediaUploadException
     */
    private function store(?int $uploadedBy, UploadedFile $file, ?MediaFolder $folder, ?int $pickedSize, ?string $pickedExtension): Media
    {
        $type = MediaUploadChecks::validate($file, $pickedSize);
        $uploadedSize = (int) $file->getSize();

        $contents = (string) file_get_contents($file->getRealPath());
        $extension = $type['ext'];
        $mime = $type['mime'];
        $width = $height = $fingerprint = $thumbnail = $failure = null;
        $animated = false;

        if ($type['kind'] === MediaKind::Image) {
            $result = $this->optimizer->handle($contents, $mime);

            if (in_array($result->mime, OptimizeImage::MUST_CONVERT, true)) {
                throw MediaUploadException::heicUnsupported();
            }

            [$thumbnail, $animated] = [$result->thumbnail, $result->animated];
            $original = $contents;
            [$contents, $mime, $extension] = [$result->contents, $result->mime, $result->extension];
            [$width, $height, $fingerprint, $failure] = [$result->width, $result->height, $result->fingerprint, $result->failure];
        }

        $disk = MediaDisks::diskFor((string) config('media.disk'), $type['kind'], $mime);
        $originalsDisk = MediaDisks::originalsDisk($disk);
        $key = (string) Str::ulid();
        $version = 1;
        $path = MediaPaths::file($disk, $key, $version, $extension, now());
        $originalPath = isset($original) && config('media.optimize.enabled') && config('media.optimize.keep_originals')
            ? MediaPaths::original($disk, $key, $version, $type['ext'])
            : null;
        $thumbPath = $thumbnail === null ? null : MediaPaths::thumb($disk, $key, $version);

        $written = [];

        try {
            foreach ([[$path, $contents, $mime, $disk], [$originalPath, $original ?? null, $type['mime'], $originalsDisk], [$thumbPath, $thumbnail, 'image/webp', $disk]] as [$target, $bytes, $targetMime, $targetDisk]) {
                if ($target !== null && $bytes !== null) {
                    $written[$targetDisk][] = $target;
                    MediaPaths::write(Storage::disk($targetDisk), $targetDisk, $target, $bytes, $targetMime);
                }
            }

            $media = new Media([
                'folder_id' => $folder?->id,
                'uploaded_by' => $uploadedBy,
                'disk' => $disk,
                'path' => $path,
                'original_path' => $originalPath,
                'thumb_path' => $thumbPath,
                'name' => MediaNames::unique($file->getClientOriginalName(), $extension),
                'kind' => $type['kind'],
                'mime' => $mime,
                'size' => strlen($contents),
                'width' => $width,
                'height' => $height,
                'original_size' => $pickedSize ?? $uploadedSize,
                'uploaded_extension' => MediaUploadChecks::pickedExtension($pickedExtension) ?? $type['ext'],
                'optimized_with' => $fingerprint,
                'optimize_failure' => $failure,
                'animated' => $animated,
                'version' => $version,
            ]);
            $media->key = $key;
            $media->save();
        } catch (Throwable $e) {
            foreach ($written as $writtenDisk => $files) {
                try {
                    Storage::disk((string) $writtenDisk)->delete($files);
                } catch (Throwable) {
                    // The original exception matters more than a failed cleanup.
                }
            }

            if ($e instanceof MediaWriteException) {
                // The storage is at fault, not the file: logged for the admin, a plain message for the uploader.
                report($e);

                throw MediaUploadException::storageFailed($e);
            }

            throw $e;
        }

        return $media;
    }
}

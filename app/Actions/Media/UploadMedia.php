<?php

namespace App\Actions\Media;

use App\Enums\MediaKind;
use App\Exceptions\MediaUploadException;
use App\Models\Media;
use App\Models\MediaFolder;
use App\Models\User;
use App\Support\Media\MediaNames;
use App\Support\Media\MediaTypes;
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
     *
     * @throws MediaUploadException
     */
    public function handle(User $actor, UploadedFile $file, ?MediaFolder $folder = null, ?int $pickedSize = null): Media
    {
        Gate::forUser($actor)->authorize('create', Media::class);

        $type = MediaTypes::detect($file);

        if ($type === null) {
            throw MediaUploadException::unsupported($file->getClientOriginalName());
        }

        $uploadedSize = (int) $file->getSize();
        $checkedSize = max($pickedSize ?? 0, $uploadedSize);

        if ($checkedSize > MediaTypes::limitBytes($type['kind'])) {
            throw MediaUploadException::tooLarge($checkedSize, MediaTypes::limitBytes($type['kind']));
        }

        $contents = (string) file_get_contents($file->getRealPath());
        $extension = $type['ext'];
        $mime = $type['mime'];
        $width = $height = $fingerprint = $thumbnail = null;

        if ($type['kind'] === MediaKind::Image) {
            $result = $this->optimizer->handle($contents, $mime);

            if ($result->failure === 'unreadable' && in_array($type['ext'], ['heic', 'heif'], true)) {
                throw MediaUploadException::heicUnsupported();
            }

            $thumbnail = $this->optimizer->thumbnail($result->contents);
            $original = $contents;
            [$contents, $mime, $extension] = [$result->contents, $result->mime, $result->extension];
            [$width, $height, $fingerprint] = [$result->width, $result->height, $result->fingerprint];
        }

        $disk = (string) config('media.disk');
        $storage = Storage::disk($disk);
        $key = (string) Str::ulid();
        $path = sprintf('media/%s/%s.%s', now()->format('Y/m'), $key, $extension);
        $originalPath = isset($original) && config('media.optimize.keep_originals')
            ? sprintf('media/originals/%s.%s', $key, $type['ext'])
            : null;
        $thumbPath = $thumbnail === null ? null : "media/thumbs/{$key}.webp";

        $written = [];

        try {
            foreach ([[$path, $contents], [$originalPath, $original ?? null], [$thumbPath, $thumbnail]] as [$target, $bytes]) {
                if ($target !== null && $bytes !== null) {
                    $storage->put($target, $bytes);
                    $written[] = $target;
                }
            }

            $media = new Media([
                'folder_id' => $folder?->id,
                'uploaded_by' => $actor->id,
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
                'optimized_with' => $fingerprint,
            ]);
            $media->key = $key;
            $media->save();
        } catch (Throwable $e) {
            try {
                $storage->delete($written);
            } catch (Throwable) {
                // The original exception matters more than a failed cleanup.
            }

            throw $e;
        }

        return $media;
    }
}

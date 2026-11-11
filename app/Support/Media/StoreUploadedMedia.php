<?php

namespace App\Support\Media;

use App\Actions\Media\UploadMedia;
use App\Exceptions\MediaUploadException;
use App\Models\Media;
use App\Models\MediaFolder;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Storage;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Throwable;

/**
 * Turns a temporary Livewire upload into a library file for the library page and the media picker.
 * A rejected file returns its error text instead of throwing, so an upload queue can go on.
 */
class StoreUploadedMedia
{
    public function __construct(private readonly UploadMedia $uploadMedia) {}

    /**
     * @param  list<string>|null  $allowedExtensions  Lowercase extensions the stored file may have; null means any.
     * @return array{ok: bool, id?: int, name?: string, meta?: string, error?: string}
     */
    public function handle(
        User $actor,
        TemporaryUploadedFile $file,
        ?int $folderId,
        ?int $pickedSize,
        ?string $pickedExtension,
        ?array $allowedExtensions = null,
    ): array {
        $folder = $folderId === null ? null : MediaFolder::query()->find($folderId);

        if ($folderId !== null && $folder === null) {
            $file->delete();

            return ['ok' => false, 'error' => __('media.errors.folder_missing')];
        }

        try {
            $media = $this->uploadMedia->handle($actor, $file, $folder, $pickedSize, $pickedExtension);
        } catch (MediaUploadException $exception) {
            return ['ok' => false, 'error' => $exception->getMessage()];
        } catch (AuthorizationException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            report($exception);

            return ['ok' => false, 'error' => __('media.upload.failed')];
        } finally {
            $file->delete();
        }

        if ($allowedExtensions !== null && ! in_array(strtolower($media->extension()), $allowedExtensions, true)) {
            $this->discard($media);

            return ['ok' => false, 'error' => __('media.picker.not_allowed')];
        }

        return [
            'ok' => true,
            'id' => $media->id,
            'name' => $media->name,
            'meta' => $media->savedBytes() === null
                ? __('media.upload.done', ['size' => $media->humanSize()])
                : __('media.upload.done_compressed', [
                    'before' => Media::formatBytes((int) $media->original_size),
                    'after' => $media->humanSize(),
                ]),
        ];
    }

    /**
     * Remove a file just stored, without the delete permission: the actor may upload but not delete,
     * and this file was never shown to anyone.
     */
    private function discard(Media $media): void
    {
        $files = array_filter([$media->path, $media->original_path, $media->thumb_path]);

        $media->delete();

        try {
            Storage::disk($media->disk)->delete($files);
        } catch (Throwable $exception) {
            report($exception);
        }
    }
}

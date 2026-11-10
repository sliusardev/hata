<?php

namespace App\Actions\Media;

use App\Exceptions\MediaEditException;
use App\Exceptions\MediaUploadException;
use App\Models\Media;
use App\Support\Media\ImageEdits;
use App\Support\Media\MediaNames;
use App\Support\Media\MediaTypes;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use Throwable;

/**
 * Replaces the stored file of a media row (and its thumbnail, and optionally its kept original) with new bytes,
 * keeping the row's id and key. Used by crop, restore-original and replace; the callers authorize.
 *
 * Old files the new ones don't overwrite are deleted after commit. If anything fails, the row and its old files
 * are left exactly as before: paths shared with the old row get their old bytes back, other new files are deleted.
 */
class RebuildMediaFile
{
    public function __construct(private OptimizeImage $optimizer) {}

    /**
     * @param  string  $contents  The bytes to build the file from (the kept original, the current file or an upload).
     * @param  string  $mime  The canonical MIME of $contents.
     * @param  array<string, mixed>|null  $edits  Crop/rotate/flip/size (see ImageEdits), applied to images. Non-empty edits that
     *                                            can't be applied throw MediaEditException and write nothing.
     * @param  string|null  $newName  The file name to store; by default the name changes only when the extension does.
     * @param  string|null  $newOriginal  Bytes to keep as the new original at media/originals/{key}.{ext}; null keeps the current one.
     * @param  bool  $keepEdits  False stores `edits` as null although they were applied (baked into a file with no original).
     * @param  bool  $dropOriginal  Clear `original_path` and delete the old original after commit. Can't be combined with $newOriginal.
     * @param  array<string, mixed>  $attributes  Extra columns saved in the same update (e.g. original_size, uploaded_extension).
     *
     * @throws MediaEditException when non-empty edits can't be applied (not editable, too large or unreadable)
     * @throws MediaUploadException when the image is HEIC the server can't convert
     * @throws InvalidArgumentException when the edits are malformed, or $newOriginal is given with $dropOriginal
     */
    public function handle(
        Media $media,
        string $contents,
        string $mime,
        ?array $edits,
        ?string $newName = null,
        ?string $newOriginal = null,
        bool $keepEdits = true,
        bool $dropOriginal = false,
        array $attributes = [],
    ): Media {
        if ($dropOriginal && $newOriginal !== null) {
            throw new InvalidArgumentException('A new original can not be stored while dropping the original.');
        }

        $imageEdits = ImageEdits::fromArray($edits);
        $imageEdits = $imageEdits?->isEmpty() ? null : $imageEdits;
        $width = $height = $fingerprint = $failure = $thumbnail = null;

        if ($media->isImage()) {
            $result = $this->optimizer->handle($contents, $mime, $imageEdits?->toArray());

            if ($imageEdits !== null && $result->failure !== null) {
                throw MediaEditException::because($result->failure);
            }

            if (in_array($result->mime, OptimizeImage::MUST_CONVERT, true)) {
                throw MediaUploadException::heicUnsupported();
            }

            $thumbnail = $this->optimizer->thumbnail($result->contents);
            [$contents, $mime, $extension] = [$result->contents, $result->mime, $result->extension];
            [$width, $height, $fingerprint, $failure] = [$result->width, $result->height, $result->fingerprint, $result->failure];
        } elseif ($imageEdits !== null) {
            throw MediaEditException::because('not_editable');
        } else {
            $extension = MediaTypes::extensionFor($mime) ?? pathinfo($media->path, PATHINFO_EXTENSION);
        }

        $disk = Storage::disk($media->disk);
        $path = sprintf('media/%s/%s.%s', now()->format('Y/m'), $media->key, $extension);
        $thumbPath = $thumbnail === null ? null : "media/thumbs/{$media->key}.webp";
        $originalPath = match (true) {
            $dropOriginal => null,
            $newOriginal !== null => sprintf('media/originals/%s.%s', $media->key, $this->originalExtension($newOriginal, $contents === $newOriginal ? $mime : null)),
            default => $media->original_path,
        };

        $name = $newName ?? (strtolower(pathinfo($media->name, PATHINFO_EXTENSION)) === $extension
            ? $media->name
            : MediaNames::unique($media->name, $extension, ignoreId: $media->id));

        $stale = array_values(array_unique(array_filter(
            [
                $media->path === $path ? null : $media->path,
                $media->thumb_path === $thumbPath ? null : $media->thumb_path,
                $media->original_path === $originalPath ? null : $media->original_path,
            ],
            fn (?string $file): bool => $file !== null && $file !== '',
        )));

        $attributesBefore = $media->getAttributes();
        $originalBefore = $media->getRawOriginal();
        $backups = [];
        $written = [];

        try {
            foreach ([[$path, $contents], [$thumbPath, $thumbnail], [$newOriginal === null ? null : $originalPath, $newOriginal]] as [$target, $bytes]) {
                if ($target === null || $bytes === null) {
                    continue;
                }

                if (! array_key_exists($target, $backups) && $disk->exists($target)) {
                    $backups[$target] = (string) $disk->get($target);
                }

                $written[] = $target;
                $disk->put($target, $bytes);
            }

            DB::transaction(function () use ($media, $attributes, $path, $thumbPath, $originalPath, $name, $mime, $contents, $width, $height, $fingerprint, $failure, $imageEdits, $keepEdits, $stale): void {
                $media->fill($attributes)->fill([
                    'path' => $path,
                    'thumb_path' => $thumbPath,
                    'original_path' => $originalPath,
                    'name' => $name,
                    'mime' => $mime,
                    'size' => strlen($contents),
                    'width' => $width,
                    'height' => $height,
                    'optimized_with' => $fingerprint,
                    'optimize_failure' => $failure,
                    'edits' => $keepEdits ? $imageEdits?->toArray() : null,
                    'version' => $media->version + 1,
                ]);
                $media->save();

                DB::afterCommit(function () use ($media, $stale): void {
                    try {
                        Storage::disk($media->disk)->delete($stale);
                    } catch (Throwable $e) {
                        report($e);
                    }
                });
            });
        } catch (Throwable $e) {
            $this->undoWrites($disk, $written, $backups);
            $media->setRawAttributes($originalBefore, sync: true);
            $media->setRawAttributes($attributesBefore);

            throw $e;
        }

        return $media;
    }

    /**
     * The extension of a new original: from the given MIME when the original is the source itself, else sniffed.
     */
    private function originalExtension(string $original, ?string $mime): string
    {
        $mime ??= MediaTypes::sniff($original);

        return ($mime === null ? null : MediaTypes::extensionFor($mime)) ?? 'bin';
    }

    /**
     * Put the old bytes back on paths the old row used, and delete the other new files.
     *
     * @param  list<string>  $written
     * @param  array<string, string>  $backups
     */
    private function undoWrites(Filesystem $disk, array $written, array $backups): void
    {
        foreach (array_unique($written) as $target) {
            try {
                array_key_exists($target, $backups) ? $disk->put($target, $backups[$target]) : $disk->delete($target);
            } catch (Throwable $e) {
                // The original exception matters more than a failed cleanup.
                report($e);
            }
        }
    }
}

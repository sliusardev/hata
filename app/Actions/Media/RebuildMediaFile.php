<?php

namespace App\Actions\Media;

use App\Exceptions\MediaChangedException;
use App\Exceptions\MediaEditException;
use App\Exceptions\MediaUploadException;
use App\Models\Media;
use App\Support\Media\ImageEdits;
use App\Support\Media\MediaNames;
use App\Support\Media\MediaTypes;
use App\Support\Media\OptimizedImage;
use Closure;
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
     * @param  string|(Closure(string): string)|null  $newName  The file name to store, or a closure that gets the extension of the result and returns it;
     *                                                          by default the name changes only when the extension does.
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
        string|Closure|null $newName = null,
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
        $sourceMime = $mime;

        if ($media->isImage()) {
            $result = $this->optimizer->handle($contents, $mime, $imageEdits?->toArray());

            if ($imageEdits !== null && $result->failure !== null) {
                throw MediaEditException::because($result->failure);
            }

            if (in_array($result->mime, OptimizeImage::MUST_CONVERT, true)) {
                throw MediaUploadException::heicUnsupported();
            }
        } elseif ($imageEdits !== null) {
            throw MediaEditException::because('not_editable');
        } else {
            $extension = MediaTypes::extensionFor($mime) ?? pathinfo($media->path, PATHINFO_EXTENSION);
            $result = new OptimizedImage($contents, $mime, $extension, null, null, false);
        }

        return $this->store(
            $media,
            $result,
            $imageEdits,
            $newName,
            $newOriginal,
            $newOriginal !== null && $contents === $newOriginal ? $sourceMime : null,
            $keepEdits,
            $dropOriginal,
            $attributes,
        );
    }

    /**
     * Store an already optimized result: everything handle() does after optimizing. No decoding happens here;
     * the thumbnail and `animated` come from $result.
     *
     * @param  ImageEdits|null  $edits  The edits that were applied to produce $result (null or empty: none).
     * @param  string|null  $newOriginalMime  The canonical MIME of $newOriginal when known (saves sniffing it).
     * @param  array<string, mixed>  $attributes
     * @param  int|null  $expectedVersion  The version the caller loaded; when given, the row is rewritten only while it
     *                                     still has it (checked under the row lock), so a crop or replace stored
     *                                     meanwhile is never undone. Null: no check.
     *
     * @throws InvalidArgumentException when $newOriginal is given with $dropOriginal
     * @throws MediaChangedException when $expectedVersion is given and the row has another version or is gone; nothing
     *                               is kept: the files are rolled back, or for a deleted row the written ones removed
     */
    public function store(
        Media $media,
        OptimizedImage $result,
        ?ImageEdits $edits,
        string|Closure|null $newName = null,
        ?string $newOriginal = null,
        ?string $newOriginalMime = null,
        bool $keepEdits = true,
        bool $dropOriginal = false,
        array $attributes = [],
        ?int $expectedVersion = null,
    ): Media {
        if ($dropOriginal && $newOriginal !== null) {
            throw new InvalidArgumentException('A new original can not be stored while dropping the original.');
        }

        $imageEdits = $edits?->isEmpty() ? null : $edits;
        [$contents, $mime, $extension, $thumbnail, $animated] = [$result->contents, $result->mime, $result->extension, $result->thumbnail, $result->animated];
        [$width, $height, $fingerprint, $failure] = [$result->width, $result->height, $result->fingerprint, $result->failure];

        $disk = Storage::disk($media->disk);
        $path = sprintf('media/%s/%s.%s', now()->format('Y/m'), $media->key, $extension);
        $thumbPath = $thumbnail === null ? null : "media/thumbs/{$media->key}.webp";
        $originalPath = match (true) {
            $dropOriginal => null,
            $newOriginal !== null => sprintf('media/originals/%s.%s', $media->key, $this->originalExtension($newOriginal, $newOriginalMime)),
            default => $media->original_path,
        };

        $name = ($newName instanceof Closure ? $newName($extension) : $newName) ?? (strtolower(pathinfo($media->name, PATHINFO_EXTENSION)) === $extension
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

            DB::transaction(function () use ($media, $attributes, $path, $thumbPath, $originalPath, $name, $mime, $contents, $width, $height, $fingerprint, $failure, $animated, $imageEdits, $keepEdits, $stale, $expectedVersion): void {
                $stored = Media::query()->whereKey($media->getKey())->lockForUpdate()->value('version');

                if ($expectedVersion !== null && $stored === null) {
                    throw MediaChangedException::gone();
                }

                if ($expectedVersion !== null && (int) $stored !== $expectedVersion) {
                    throw MediaChangedException::changed();
                }

                $version = (int) $stored;

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
                    'animated' => $animated,
                    'edits' => $keepEdits ? $imageEdits?->toArray() : null,
                    'version' => $version + 1,
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
            // A deleted row's old files belong to its deletion (which may already have removed them): restoring them
            // would leave orphans, so everything written here is removed instead.
            $this->undoWrites($disk, $written, $e instanceof MediaChangedException && $e->gone ? [] : $backups);
            $media->setRawAttributes($originalBefore, sync: true);
            $media->setRawAttributes($attributesBefore);

            throw $e;
        }

        return $media;
    }

    /**
     * Record that the file already matches the current optimization settings, without touching files or the version
     * (the bytes are identical, so cached URLs stay valid). Other dirty attributes (e.g. cleared `edits`) are saved too.
     *
     * @param  int|null  $expectedVersion  When given, the row is updated only while it still has this version.
     *
     * @throws MediaChangedException when $expectedVersion is given and the row has another version or is gone
     */
    public function markOptimized(Media $media, string $fingerprint, ?int $expectedVersion = null): void
    {
        $media->forceFill(['optimized_with' => $fingerprint, 'optimize_failure' => null]);

        if ($expectedVersion === null) {
            $media->save();

            return;
        }

        $updated = Media::query()->whereKey($media->getKey())->where('version', $expectedVersion)->update($media->getDirty());

        if ($updated === 0) {
            throw Media::query()->whereKey($media->getKey())->exists() ? MediaChangedException::changed() : MediaChangedException::gone();
        }

        $media->syncOriginal();
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

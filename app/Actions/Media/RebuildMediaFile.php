<?php

namespace App\Actions\Media;

use App\Exceptions\MediaChangedException;
use App\Exceptions\MediaEditException;
use App\Exceptions\MediaUploadException;
use App\Models\Media;
use App\Models\MediaPendingDeletion;
use App\Support\Media\ImageEdits;
use App\Support\Media\MediaDisks;
use App\Support\Media\MediaNames;
use App\Support\Media\MediaPaths;
use App\Support\Media\MediaTypes;
use App\Support\Media\OptimizedImage;
use Closure;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use Throwable;

/**
 * Replaces the stored file of a media row (and its thumbnail, and optionally its kept original) with new bytes,
 * keeping the row's id and key. Used by crop, restore-original and replace; the callers authorize.
 *
 * The file and thumbnail live on the row's disk, the kept original on its originals disk (Media::originalsDisk()).
 * Old files the new ones don't overwrite are deleted after commit; on a disk whose paths carry the version (a cloud
 * disk, or the public disk served as static files) they are scheduled for deletion in 7 days instead
 * (MediaPendingDeletion), each on the disk it lives on, so pages rendered before keep working.
 * If anything fails, the row and its old files are left exactly as before: paths shared with the old row get their
 * old bytes back, other new files are deleted.
 */
class RebuildMediaFile
{
    /** Seconds the per-row rebuild lock lives at most (it is released as soon as the rebuild ends). */
    public const int LOCK_SECONDS = 120;

    public function __construct(private OptimizeImage $optimizer) {}

    /**
     * @param  string  $contents  The bytes to build the file from (the kept original, the current file or an upload).
     * @param  string  $mime  The canonical MIME of $contents.
     * @param  array<string, mixed>|null  $edits  Crop/rotate/flip/size (see ImageEdits), applied to images. Non-empty edits that
     *                                            can't be applied throw MediaEditException and write nothing.
     * @param  string|(Closure(string): string)|null  $newName  The file name to store, or a closure that gets the extension of the result and returns it;
     *                                                          by default the name changes only when the extension does.
     * @param  string|null  $newOriginal  Bytes to keep as the new original (MediaPaths::original()); null keeps the current one.
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
     * @param  int|null  $expectedVersion  The version the caller loaded; null: the version of $media as loaded. The row is
     *                                     rewritten only while it still has it (checked before writing and under the row
     *                                     lock), so a crop or replace stored meanwhile is never undone or overwritten.
     *
     * @throws InvalidArgumentException when $newOriginal is given with $dropOriginal
     * @throws MediaChangedException when the row has another version than $expectedVersion (else the loaded one) or is
     *                               gone, or another rebuild of it holds the lock; nothing is kept: the files are rolled
     *                               back, or for a deleted row the written ones removed
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

        // The version the new paths and the update are built on: the caller's, else the loaded one (none for a new row).
        $basis = $expectedVersion ?? ($media->exists ? (int) $media->version : null);
        $lock = $media->exists ? Cache::lock(self::lockName($media), self::LOCK_SECONDS) : null;

        // Another rebuild of this row is writing right now: its paths are the ones this one would use.
        if ($lock !== null && ! $lock->get()) {
            throw MediaChangedException::changed();
        }

        try {
            return $this->storeLocked($media, $result, $imageEdits, $newName, $newOriginal, $newOriginalMime, $keepEdits, $dropOriginal, $attributes, $basis);
        } finally {
            $lock?->release();
        }
    }

    /**
     * The cache lock a rebuild of the row holds while it writes and commits.
     */
    public static function lockName(Media $media): string
    {
        return 'media-rebuild:'.$media->getKey();
    }

    /**
     * store() under the row's rebuild lock.
     *
     * @param  array<string, mixed>  $attributes
     * @param  int|null  $basis  The version the row must still have; null for a row not saved yet.
     *
     * @throws MediaChangedException
     */
    private function storeLocked(
        Media $media,
        OptimizedImage $result,
        ?ImageEdits $imageEdits,
        string|Closure|null $newName,
        ?string $newOriginal,
        ?string $newOriginalMime,
        bool $keepEdits,
        bool $dropOriginal,
        array $attributes,
        ?int $basis,
    ): Media {
        // A newer version was committed after the row was loaded: with versioned paths its objects carry the very paths
        // built below, so nothing may be written. (A deleted row is caught under the row lock, as before.)
        if ($basis !== null) {
            $stored = Media::query()->whereKey($media->getKey())->value('version');

            if ($stored !== null && (int) $stored !== $basis) {
                throw MediaChangedException::changed();
            }
        }

        [$contents, $mime, $extension, $thumbnail, $animated] = [$result->contents, $result->mime, $result->extension, $result->thumbnail, $result->animated];
        [$width, $height, $fingerprint, $failure] = [$result->width, $result->height, $result->fingerprint, $result->failure];

        $diskName = $media->disk;
        $originalsDisk = $media->originalsDisk();
        // On a cloud or publishing disk the paths carry the version this rebuild stores (the original's too: it is
        // named with the row's disk), so the new objects never overwrite the old.
        $versioned = MediaDisks::versionsPaths($diskName);
        $nextVersion = ($basis ?? (int) $media->version) + 1;
        $path = MediaPaths::file($diskName, $media->key, $nextVersion, $extension, now());
        $thumbPath = $thumbnail === null ? null : MediaPaths::thumb($diskName, $media->key, $nextVersion);
        $newOriginalMime = $newOriginal === null ? null : ($newOriginalMime ?? MediaTypes::sniff($newOriginal));
        $originalPath = match (true) {
            $dropOriginal => null,
            $newOriginal !== null => MediaPaths::original($diskName, $media->key, $nextVersion, ($newOriginalMime === null ? null : MediaTypes::extensionFor($newOriginalMime)) ?? 'bin'),
            default => $media->original_path,
        };

        $name = ($newName instanceof Closure ? $newName($extension) : $newName) ?? (strtolower(pathinfo($media->name, PATHINFO_EXTENSION)) === $extension
            ? $media->name
            : MediaNames::unique($media->name, $extension, ignoreId: $media->id));

        /** @var array<string, list<string>> $stale Disk => the old paths on it the new row no longer uses. */
        $stale = [];

        foreach ([[$diskName, $media->path, $path], [$diskName, $media->thumb_path, $thumbPath], [$originalsDisk, $media->original_path, $originalPath]] as [$on, $old, $new]) {
            if ($old !== null && $old !== '' && $old !== $new && ! in_array($old, $stale[$on] ?? [], true)) {
                $stale[$on][] = $old;
            }
        }

        $attributesBefore = $media->getAttributes();
        $originalBefore = $media->getRawOriginal();
        /** @var array<string, array<string, string>> $backups Disk => path => the bytes it held before. */
        $backups = [];
        /** @var list<array{0: string, 1: string, 2: string}> $written Disk, path and MIME of each file written. */
        $written = [];

        try {
            $targets = [
                [$diskName, $path, $contents, $mime],
                [$diskName, $thumbPath, $thumbnail, 'image/webp'],
                [$originalsDisk, $newOriginal === null ? null : $originalPath, $newOriginal, $newOriginalMime ?? 'application/octet-stream'],
            ];

            foreach ($targets as [$on, $target, $bytes, $targetMime]) {
                if ($target === null || $bytes === null) {
                    continue;
                }

                $storage = Storage::disk($on);

                // A new versioned path can't hold a committed object (the version was checked under the lock), so only
                // unversioned paths, which every version shares, need a backup.
                if (! $versioned && ! isset($backups[$on][$target]) && $storage->exists($target)) {
                    $backups[$on][$target] = (string) $storage->get($target);
                }

                $written[] = [$on, $target, $targetMime];
                MediaPaths::write($storage, $on, $target, $bytes, $targetMime);
            }

            DB::transaction(function () use ($media, $attributes, $path, $thumbPath, $originalPath, $name, $mime, $contents, $width, $height, $fingerprint, $failure, $animated, $imageEdits, $keepEdits, $stale, $basis, $versioned): void {
                $stored = Media::query()->whereKey($media->getKey())->lockForUpdate()->value('version');

                if ($basis !== null && $stored === null) {
                    throw MediaChangedException::gone();
                }

                if ($basis !== null && (int) $stored !== $basis) {
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

                if ($versioned) {
                    // Pages and CDN or browser caches may still point at the replaced objects: they go after a delay,
                    // recorded in this transaction so they are scheduled exactly when the new version is committed.
                    foreach ($stale as $on => $paths) {
                        MediaPendingDeletion::schedule($on, $paths);
                    }

                    return;
                }

                DB::afterCommit(function () use ($stale): void {
                    foreach ($stale as $on => $paths) {
                        try {
                            Storage::disk($on)->delete($paths);
                        } catch (Throwable $e) {
                            report($e);
                        }
                    }
                });
            });
        } catch (Throwable $e) {
            // A deleted row's old files belong to its deletion (which may already have removed them): restoring them
            // would leave orphans, so everything written here is removed instead.
            $this->undoWrites($this->notCommitted($media, $written, $versioned), $e instanceof MediaChangedException && $e->gone ? [] : $backups);
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
     * The written files that the stored row doesn't point at now. With versioned paths a rebuild that won the row after
     * this one's lock expired committed the same paths: deleting them would leave its row pointing at nothing.
     *
     * @param  list<array{0: string, 1: string, 2: string}>  $written
     * @return list<array{0: string, 1: string, 2: string}>
     */
    private function notCommitted(Media $media, array $written, bool $versioned): array
    {
        if (! $versioned || $written === [] || ! $media->exists) {
            return $written;
        }

        $stored = Media::query()->whereKey($media->getKey())->first(['disk', 'path', 'thumb_path', 'original_path']);

        if ($stored === null) {
            return $written;
        }

        $committed = [
            [$stored->disk, $stored->path],
            [$stored->disk, $stored->thumb_path],
            [$stored->originalsDisk(), $stored->original_path],
        ];

        return array_values(array_filter(
            $written,
            fn (array $file): bool => ! in_array([$file[0], $file[1]], $committed, true),
        ));
    }

    /**
     * Put the old bytes back on paths the old row used, and delete the other new files.
     *
     * @param  list<array{0: string, 1: string, 2: string}>  $written  Disk, path and MIME of each file written.
     * @param  array<string, array<string, string>>  $backups  Disk => path => old bytes.
     */
    private function undoWrites(array $written, array $backups): void
    {
        foreach ($written as [$on, $target, $mime]) {
            try {
                isset($backups[$on][$target])
                    ? Storage::disk($on)->put($target, $backups[$on][$target], MediaPaths::writeOptions($on, $mime))
                    : Storage::disk($on)->delete($target);
            } catch (Throwable $e) {
                // The original exception matters more than a failed cleanup.
                report($e);
            }
        }
    }
}

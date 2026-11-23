<?php

namespace Hata\Actions\Media;

use Hata\Contracts\AdminUser;
use Hata\Models\Media;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;
use ZipArchive;

/**
 * Packs media files into a ZIP archive in the system temp directory.
 */
class ZipMedia
{
    /**
     * The most files one archive holds.
     */
    public const int MAX_FILES = 100;

    /**
     * Each file is copied from its disk with a stream into a temp file, so remote disks work too.
     * The caller deletes the returned archive once it is sent.
     *
     * @param  iterable<Media>  $media
     * @return string|null Path of the archive, or null when none of the files could be read.
     */
    public function handle(AdminUser $actor, iterable $media): ?string
    {
        Gate::forUser($actor)->authorize('viewAny', Media::class);

        $archivePath = $this->tempPath();
        $parts = [];
        $names = [];
        $zip = new ZipArchive;

        try {
            if ($zip->open($archivePath, ZipArchive::OVERWRITE) !== true) {
                throw new RuntimeException('Cannot create the ZIP archive.');
            }

            foreach ($media as $item) {
                $part = $this->copyToTemp($item);

                if ($part === null) {
                    continue;
                }

                $parts[] = $part;
                $zip->addFile($part, $this->entryName($item->name, $names));
            }

            $added = $zip->numFiles;

            if (! $zip->close()) {
                throw new RuntimeException('Cannot write the ZIP archive.');
            }
        } catch (Throwable $e) {
            @unlink($archivePath);

            throw $e;
        } finally {
            foreach ($parts as $part) {
                @unlink($part);
            }
        }

        if ($added === 0) {
            @unlink($archivePath);

            return null;
        }

        return $archivePath;
    }

    /**
     * Copy a stored file into a temp file; null when the file is missing on its disk.
     */
    private function copyToTemp(Media $media): ?string
    {
        $source = Storage::disk($media->disk)->readStream($media->path);

        if (! is_resource($source)) {
            return null;
        }

        $path = $this->tempPath();
        $target = fopen($path, 'wb');

        try {
            if ($target === false || stream_copy_to_stream($source, $target) === false) {
                @unlink($path);

                return null;
            }
        } finally {
            fclose($source);

            if (is_resource($target)) {
                fclose($target);
            }
        }

        return $path;
    }

    /**
     * The file's name inside the archive, made unique with "-2", "-3" like library names.
     *
     * @param  array<string, true>  $taken
     */
    private function entryName(string $name, array &$taken): string
    {
        $name = basename($name);
        $extension = pathinfo($name, PATHINFO_EXTENSION);
        $base = pathinfo($name, PATHINFO_FILENAME);
        $candidate = $name;

        for ($suffix = 2; isset($taken[$candidate]); $suffix++) {
            $candidate = $base.'-'.$suffix.($extension === '' ? '' : '.'.$extension);
        }

        $taken[$candidate] = true;

        return $candidate;
    }

    private function tempPath(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'media-zip-');

        if ($path === false) {
            throw new RuntimeException('Cannot create a temp file.');
        }

        return $path;
    }
}

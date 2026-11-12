<?php

namespace App\Support\Media;

use App\Exceptions\MediaWriteException;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Filesystem\Filesystem;

/**
 * Where media objects are stored and how they are written.
 *
 * On a private local disk the paths are the same for every version (`media/{Y/m}/{key}.{ext}`, `media/thumbs/{key}.webp`,
 * `media/originals/{key}.{ext}`), and a rebuild overwrites or deletes them at once. On a cloud disk or a disk served
 * as static files they carry the version
 * (`{key}-v{version}`), so a CDN that ignores the query string never serves a stale file and a page rendered
 * before a rebuild keeps working while the replaced objects wait for their deferred deletion.
 */
class MediaPaths
{
    /** Cache header of cloud objects: matches the week the media route caches the current version (decision 45). */
    public const string CACHE_CONTROL = 'public, max-age=604800';

    public static function file(string $disk, string $key, int $version, string $ext, CarbonInterface $at): string
    {
        return sprintf('media/%s/%s.%s', $at->format('Y/m'), self::name($disk, $key, $version), $ext);
    }

    public static function thumb(string $disk, string $key, int $version): string
    {
        return sprintf('media/thumbs/%s.webp', self::name($disk, $key, $version));
    }

    public static function original(string $disk, string $key, int $version, string $ext): string
    {
        return sprintf('media/originals/%s.%s', self::name($disk, $key, $version), $ext);
    }

    /**
     * The options every media object is written with: on a cloud disk its content type and cache header (the
     * store would otherwise guess the type and send no cache header); none on a local disk.
     *
     * An SVG object is stored as an inert download (`application/octet-stream`, `attachment`): opened straight from
     * the bucket or CDN it could run script on that origin. The app streams SVG with its own type and sandbox headers
     * (decision 50), so the stored type is never what a visitor gets.
     *
     * @return array<string, string>
     */
    public static function writeOptions(string $disk, string $mime): array
    {
        if (! MediaDisks::isCloud($disk)) {
            return [];
        }

        if ($mime === 'image/svg+xml') {
            return ['ContentType' => 'application/octet-stream', 'ContentDisposition' => 'attachment', 'CacheControl' => self::CACHE_CONTROL];
        }

        return ['ContentType' => $mime, 'CacheControl' => self::CACHE_CONTROL];
    }

    /**
     * Write one media object with its write options.
     *
     * @throws MediaWriteException when the disk reports the object was not stored
     */
    public static function write(Filesystem $storage, string $disk, string $path, string $bytes, string $mime): void
    {
        if ($storage->put($path, $bytes, self::writeOptions($disk, $mime)) === false) {
            throw MediaWriteException::notWritten($disk, $path);
        }
    }

    private static function name(string $disk, string $key, int $version): string
    {
        return MediaDisks::versionsPaths($disk) ? "{$key}-v{$version}" : $key;
    }
}

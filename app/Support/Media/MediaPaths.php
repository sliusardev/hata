<?php

namespace App\Support\Media;

use Carbon\CarbonInterface;

/**
 * Where media objects are stored and how they are written.
 *
 * On a local disk the paths are the same for every version (`media/{Y/m}/{key}.{ext}`, `media/thumbs/{key}.webp`,
 * `media/originals/{key}.{ext}`), and a rebuild overwrites or deletes them at once. On a cloud disk they carry the
 * version (`{key}-v{version}`), so a CDN that ignores the query string never serves a stale file and a page rendered
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
     * @return array<string, string>
     */
    public static function writeOptions(string $disk, string $mime): array
    {
        if (! MediaDisks::isCloud($disk)) {
            return [];
        }

        return ['ContentType' => $mime, 'CacheControl' => self::CACHE_CONTROL];
    }

    private static function name(string $disk, string $key, int $version): string
    {
        return MediaDisks::isCloud($disk) ? "{$key}-v{$version}" : $key;
    }
}

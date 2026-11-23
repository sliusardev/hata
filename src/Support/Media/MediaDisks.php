<?php

namespace App\Support\Media;

use App\Enums\MediaKind;

/**
 * What the media code needs to know about a filesystem disk: whether files on it are reached over the network
 * (so direct URLs and signed links apply) and the public/CDN URL it is configured with.
 */
class MediaDisks
{
    /** The local disk whose files are served straight by the web server (a `/storage` symlink). */
    public const string PUBLIC = 'public';

    /** The local disk nobody can reach over HTTP: originals, SVG and documents when the site disk is `public`. */
    public const string PRIVATE = 'local';

    /** The one image type that never goes to a publishing disk: served as a static file its script would run. */
    public const string SVG_MIME = 'image/svg+xml';

    /**
     * Whether files on the disk live off this server: the disk's configured driver is anything but `local`.
     * An unknown disk is not a cloud disk.
     */
    public static function isCloud(string $disk): bool
    {
        $driver = config("filesystems.disks.{$disk}.driver");

        return is_string($driver) && $driver !== 'local';
    }

    /**
     * Whether files on the disk may be reached as static files of this server: the disk named PUBLIC with a local
     * driver, whatever its URL. Decided by identity, not by URL, because `storage/app/public` is web-reachable through
     * the link even when the configured URL is blank, so placement must keep SVG, documents and originals off it in
     * any case. Whether a static URL can be built is DirectServing's question (it needs the URL).
     */
    public static function publishes(string $disk): bool
    {
        return $disk === self::PUBLIC && config("filesystems.disks.{$disk}.driver") === 'local';
    }

    /**
     * Whether object paths on the disk carry the version (`{key}-v{version}`): when a CDN, a browser or a static
     * file server may cache by path, so a rebuild must write a new path instead of overwriting.
     */
    public static function versionsPaths(string $disk): bool
    {
        return self::isCloud($disk) || self::publishes($disk);
    }

    /**
     * The disk that keeps the originals of files on `$disk`: the private disk when `$disk` is served publicly (an
     * original must never be reachable by URL), else the disk itself.
     */
    public static function originalsDisk(string $disk): string
    {
        return self::publishes($disk) ? self::PRIVATE : $disk;
    }

    /**
     * The disk a new file is stored on, given the site's media disk: on a publishing disk only raster images go
     * there, while SVG (always streamed with a sandbox) and documents stay on the private disk.
     */
    public static function diskFor(string $siteDisk, MediaKind $kind, string $mime): string
    {
        if (! self::publishes($siteDisk)) {
            return $siteDisk;
        }

        return self::isRasterImage($kind, $mime) ? $siteDisk : self::PRIVATE;
    }

    /**
     * Whether a file may be served as a static file: an image that is not SVG. MoveMediaToDisk::candidates() states
     * the same rule in SQL.
     */
    public static function isRasterImage(MediaKind $kind, string $mime): bool
    {
        return $kind === MediaKind::Image && $mime !== self::SVG_MIME;
    }

    /**
     * The options of an S3 disk built from storage settings (keyed like the `media_s3_*` settings). Blank values
     * become null, slashes around the prefix and at the end of the URL are dropped, and an empty secret falls back
     * to the one in effect (stored, else .env), because the form never loads it.
     *
     * @param  array<string, mixed>  $settings
     * @return array<string, mixed>
     */
    public static function connectionConfig(array $settings): array
    {
        $text = fn (string $key): ?string => filled($settings[$key] ?? null) ? trim((string) $settings[$key]) : null;
        $secret = filled($settings['media_s3_secret'] ?? null) ? (string) $settings['media_s3_secret'] : config('filesystems.disks.s3.secret');

        return [
            'endpoint' => $text('media_s3_endpoint'),
            'region' => $text('media_s3_region'),
            'bucket' => $text('media_s3_bucket'),
            'key' => $text('media_s3_key'),
            'secret' => filled($secret) ? (string) $secret : null,
            'use_path_style_endpoint' => (bool) ($settings['media_s3_path_style'] ?? false),
            'root' => trim((string) $text('media_s3_prefix'), '/'),
        ];
    }

    /**
     * The disk's public base URL without a trailing slash, null when none is configured.
     */
    public static function publicUrl(string $disk): ?string
    {
        $url = config("filesystems.disks.{$disk}.url");

        if (! is_string($url) || trim($url) === '') {
            return null;
        }

        return rtrim(trim($url), '/');
    }
}

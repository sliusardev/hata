<?php

namespace App\Support\Media;

/**
 * What the media code needs to know about a filesystem disk: whether files on it are reached over the network
 * (so direct URLs and signed links apply) and the public/CDN URL it is configured with.
 */
class MediaDisks
{
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

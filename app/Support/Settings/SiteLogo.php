<?php

namespace App\Support\Settings;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;

/**
 * The logos uploaded in the site settings: one for the light theme and one for the dark theme.
 * When only one is uploaded, it is used for both themes.
 */
class SiteLogo
{
    /**
     * @var list<string>
     */
    public const array VARIANTS = ['light', 'dark'];

    public static function isCustom(): bool
    {
        return self::path('light') !== null;
    }

    /**
     * Path on the logo disk, falling back to the other variant.
     */
    public static function path(string $variant): ?string
    {
        $other = $variant === 'light' ? 'dark' : 'light';

        foreach ([$variant, $other] as $candidate) {
            $path = config("admin.logo.{$candidate}");

            if (is_string($path) && $path !== '') {
                return $path;
            }
        }

        return null;
    }

    /**
     * Versioned URL, so browsers may cache the file for good and still see a new upload.
     */
    public static function url(string $variant): ?string
    {
        $path = self::path($variant);

        return $path === null ? null : route('branding.logo', ['variant' => $variant, 'v' => substr(md5($path), 0, 8)]);
    }

    public static function disk(): Filesystem
    {
        return Storage::disk(config('admin.logo.disk'));
    }
}

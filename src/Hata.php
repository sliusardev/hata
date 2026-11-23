<?php

namespace Hata;

/**
 * Static helpers of the package.
 */
final class Hata
{
    /**
     * The absolute path of a file or folder inside the package (its root when `$relative` is empty). Package files
     * are found through this, never through `base_path()`, `resource_path()`, `app_path()` or `lang_path()`, which
     * point into the host application.
     */
    public static function path(string $relative = ''): string
    {
        $root = dirname(__DIR__);

        return $relative === '' ? $root : $root.'/'.ltrim($relative, '/');
    }
}

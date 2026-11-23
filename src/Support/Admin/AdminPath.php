<?php

namespace Hata\Support\Admin;

use Hata\Support\Localization\LanguageCatalog;
use Illuminate\Support\Facades\Route;
use InvalidArgumentException;

/**
 * The address prefix of the admin panel (`hata.admin.path`). Validated while the routes are registered, so a site
 * with cached routes pays nothing per request; reads only config and the routes registered so far, no queries.
 */
final class AdminPath
{
    public const string PATTERN = '/^[a-z0-9]+(-[a-z0-9]+)*$/';

    /**
     * First segments that belong to Hata, Fortify, Livewire, Laravel or the web server and may be registered
     * after the admin routes (so the registered-routes check cannot see them).
     *
     * @var list<string>
     */
    public const array TAKEN = [
        'login', 'logout', 'register', 'forgot-password', 'reset-password', 'two-factor-challenge', 'user',
        'email', 'confirm-password', 'passkeys', 'settings', 'dashboard', 'livewire', 'media', 'storage',
        'vendor', 'build', 'up', 'api', 'sanctum', 'index', 'robots', 'sitemap', 'favicon',
    ];

    /**
     * @throws InvalidArgumentException
     */
    public static function resolve(): string
    {
        $path = config('hata.admin.path');

        if (! is_string($path) || preg_match(self::PATTERN, $path) !== 1) {
            throw self::refuse($path, 'it must be one segment of lowercase letters, digits and hyphens (such as "cp-7f3k")');
        }

        if (app(LanguageCatalog::class)->has($path)) {
            throw self::refuse($path, 'it is a content language code and would collide with /'.$path.'/…');
        }

        if (in_array($path, self::TAKEN, true) || in_array($path, self::otherRouteSegments(), true)) {
            throw self::refuse($path, 'another route already uses it as its first segment');
        }

        return $path;
    }

    /**
     * @return list<string>
     */
    private static function otherRouteSegments(): array
    {
        $segments = [];

        foreach (Route::getRoutes()->getRoutes() as $route) {
            if (str_starts_with((string) $route->getName(), 'admin.')) {
                continue;   // the admin's own routes, when they are registered already
            }

            $segments[] = mb_strtolower(explode('/', trim($route->uri(), '/'))[0]);
        }

        return $segments;
    }

    private static function refuse(mixed $path, string $reason): InvalidArgumentException
    {
        return new InvalidArgumentException(sprintf(
            'HATA_ADMIN_PATH (hata.admin.path) is invalid: "%s" is refused because %s.',
            is_scalar($path) ? (string) $path : gettype($path),
            $reason,
        ));
    }
}

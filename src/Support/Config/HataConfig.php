<?php

namespace Hata\Support\Config;

use Hata\Hata;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\Foundation\CachesConfiguration;

/**
 * Builds the `hata` config key: the package's defaults (`config/hata.php`, the part a site may publish) plus the
 * internal data that is not configuration (`config/internal/*.php`: the language catalogue and the permission
 * groups, loaded as `hata.languages` and `hata.permissions`), with the site's published file over them.
 */
final class HataConfig
{
    /**
     * The internal files, each loaded under the key of the same name.
     *
     * @var list<string>
     */
    public const array INTERNAL = ['languages', 'permissions'];

    /**
     * Merges a published config over the defaults: nested maps are filled in key by key, so a published file
     * written for an older version still gets the keys added since. A non-empty list in the published file
     * replaces the default list as a whole (a list has no keys to line up), and an empty published array
     * leaves a default map alone.
     *
     * @param  array<string, mixed>  $defaults
     * @param  array<string, mixed>  $published
     * @return array<string, mixed>
     */
    public static function merge(array $defaults, array $published): array
    {
        foreach ($published as $key => $value) {
            $default = $defaults[$key] ?? null;

            if (is_array($value) && is_array($default) && ! array_is_list($default)) {
                $defaults[$key] = $value === [] ? $default : (array_is_list($value) ? $value : self::merge($default, $value));

                continue;
            }

            $defaults[$key] = $value;
        }

        return $defaults;
    }

    /**
     * The package's own defaults, internal data included.
     *
     * @return array<string, mixed>
     */
    public static function defaults(): array
    {
        $defaults = require Hata::path('config/hata.php');

        foreach (self::INTERNAL as $name) {
            $defaults[$name] = require Hata::path("config/internal/{$name}.php");
        }

        return $defaults;
    }

    /**
     * Sets `hata` on the repository. A cached config that already has it is left alone (the cache holds the merged
     * result). One that is reported cached but lacks it still gets it: a cache built before Hata was installed, or
     * the fresh application Testbench boots for `route:cache`, which never reads the cache file.
     */
    public static function apply(Application $app, Repository $config): void
    {
        if ($app instanceof CachesConfiguration && $app->configurationIsCached() && $config->has('hata')) {
            return;
        }

        $config->set('hata', self::merge(self::defaults(), (array) $config->get('hata', [])));
    }
}

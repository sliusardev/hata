<?php

namespace Hata;

use Hata\Contracts\AdminUser;
use Hata\Models\Concerns\IsAdminUser;
use Illuminate\Foundation\Auth\User as Authenticatable;
use LogicException;

/**
 * Static helpers of the package.
 */
final class Hata
{
    /**
     * User model classes already checked by `userModel()`.
     *
     * @var array<string, class-string<Authenticatable&AdminUser>>
     */
    private static array $validUserModels = [];

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

    /**
     * The host's user model class (`auth.providers.users.model`). Hata never names the host's class: it queries,
     * creates and relates users through this.
     *
     * @return class-string<Authenticatable&AdminUser>
     *
     * The check runs once per class in a process: this is called on every user query.
     *
     * @throws LogicException When the configured class is not an Eloquent user that implements `AdminUser` and uses
     *                        the `IsAdminUser` trait.
     */
    public static function userModel(): string
    {
        $model = config('auth.providers.users.model');

        if (is_string($model) && isset(self::$validUserModels[$model])) {
            return self::$validUserModels[$model];
        }

        if (! is_string($model)
            || ! is_subclass_of($model, AdminUser::class)
            || ! is_subclass_of($model, Authenticatable::class)
            || ! in_array(IsAdminUser::class, class_uses_recursive($model), true)) {
            throw new LogicException(sprintf(
                'The user model [%s] (config auth.providers.users.model) must extend %s, implement %s and use the %s trait.',
                is_string($model) ? $model : get_debug_type($model),
                Authenticatable::class,
                AdminUser::class,
                IsAdminUser::class,
            ));
        }

        return self::$validUserModels[$model] = $model;
    }
}

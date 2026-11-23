<?php

namespace Hata\Support\Install;

use Composer\Autoload\ClassLoader;

/**
 * The host files `hata:install` reads and edits. The command uses the application's own paths; tests bind an
 * instance under a temporary copy of Laravel's skeleton.
 */
final readonly class HostPaths
{
    public function __construct(
        public string $base,
        public string $userModel,
        public string $webRoutes,
        public string $appServiceProvider,
        public string $databaseSeeder,
        public string $composerJson,
        public string $config,
        public string $welcomeView,
    ) {}

    /**
     * The files of an application laid out as Laravel's skeleton, under `$base`.
     */
    public static function under(string $base): self
    {
        return new self(
            base: $base,
            userModel: $base.'/app/Models/User.php',
            webRoutes: $base.'/routes/web.php',
            appServiceProvider: $base.'/app/Providers/AppServiceProvider.php',
            databaseSeeder: $base.'/database/seeders/DatabaseSeeder.php',
            composerJson: $base.'/composer.json',
            config: $base.'/config/hata.php',
            welcomeView: $base.'/resources/views/welcome.blade.php',
        );
    }

    /**
     * The running application's files. The user model's file is found through Composer without loading the class,
     * so the edited model is the one the rest of the install loads.
     */
    public static function forApplication(): self
    {
        $model = config('auth.providers.users.model');

        return new self(
            base: base_path(),
            userModel: (is_string($model) ? self::classFile($model) : null) ?? app_path('Models/User.php'),
            webRoutes: base_path('routes/web.php'),
            appServiceProvider: app_path('Providers/AppServiceProvider.php'),
            databaseSeeder: database_path('seeders/DatabaseSeeder.php'),
            composerJson: base_path('composer.json'),
            config: config_path('hata.php'),
            welcomeView: resource_path('views/welcome.blade.php'),
        );
    }

    /**
     * A path relative to the application, for the report.
     */
    public function relative(string $path): string
    {
        foreach (array_unique([$this->base, realpath($this->base) ?: $this->base]) as $base) {
            if (str_starts_with($path, $base.'/')) {
                return substr($path, strlen($base) + 1);
            }
        }

        return $path;
    }

    private static function classFile(string $class): ?string
    {
        foreach (ClassLoader::getRegisteredLoaders() as $loader) {
            $file = $loader->findFile($class);

            if (is_string($file)) {
                return realpath($file) ?: $file;
            }
        }

        return null;
    }
}

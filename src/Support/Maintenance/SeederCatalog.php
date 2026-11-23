<?php

namespace Hata\Support\Maintenance;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\File;
use ReflectionClass;

/**
 * The seeders the maintenance page may run: every concrete Seeder class under database/seeders.
 */
class SeederCatalog
{
    private const string ROOT_NAMESPACE = 'Database\\Seeders\\';

    private const string MAIN = 'DatabaseSeeder';

    /**
     * Class names relative to Database\Seeders (e.g. "RoleSeeder", "Demo\PostSeeder"), DatabaseSeeder first.
     *
     * @return list<string>
     */
    public function names(): array
    {
        $directory = database_path('seeders');

        if (! is_dir($directory)) {
            return [];
        }

        $names = [];

        foreach (File::allFiles($directory) as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $name = str_replace('/', '\\', substr($file->getRelativePathname(), 0, -4));

            if ($this->isSeeder(self::ROOT_NAMESPACE.$name)) {
                $names[] = $name;
            }
        }

        sort($names);

        return array_values(array_unique(in_array(self::MAIN, $names, true) ? [self::MAIN, ...$names] : $names));
    }

    /**
     * The full class name of a listed seeder, or null for anything that is not on the list.
     *
     * @return class-string<Seeder>|null
     */
    public function resolve(string $name): ?string
    {
        /** @var class-string<Seeder>|null */
        return in_array($name, $this->names(), true) ? self::ROOT_NAMESPACE.$name : null;
    }

    private function isSeeder(string $class): bool
    {
        return class_exists($class)
            && is_subclass_of($class, Seeder::class)
            && ! (new ReflectionClass($class))->isAbstract();
    }
}

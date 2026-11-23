<?php

namespace Hata\Support\Maintenance;

use Illuminate\Database\Migrations\Migrator;

/**
 * The migrations `migrate` would run now, read through the migrator itself.
 */
class PendingMigrations
{
    /**
     * @return list<string> migration names, oldest first
     */
    public function names(): array
    {
        /** @var Migrator $migrator */
        $migrator = app('migrator');

        $files = array_keys($migrator->getMigrationFiles([database_path('migrations'), ...$migrator->paths()]));
        $ran = $migrator->repositoryExists() ? $migrator->getRepository()->getRan() : [];

        return array_values(array_diff($files, $ran));
    }
}

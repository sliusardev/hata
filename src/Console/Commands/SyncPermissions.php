<?php

namespace Hata\Console\Commands;

use Hata\Support\Permissions\PermissionRegistry;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

#[Signature('permissions:sync {--prune : Delete permissions that are no longer in the registry}')]
#[Description('Create the permissions declared in the permission registry')]
class SyncPermissions extends Command
{
    private const string GUARD = 'web';

    /**
     * Execute the console command.
     */
    public function handle(PermissionRegistry $registry, PermissionRegistrar $registrar): int
    {
        $declared = $registry->all();
        $existing = Permission::query()->where('guard_name', self::GUARD)->pluck('name')->all();

        $missing = array_values(array_diff($declared, $existing));
        $stale = array_values(array_diff($existing, $declared));

        foreach ($missing as $name) {
            Permission::create(['name' => $name, 'guard_name' => self::GUARD]);
        }

        $this->components->info(sprintf('Permissions created: %d, already present: %d.', count($missing), count($declared) - count($missing)));

        if ($stale !== []) {
            if ($this->option('prune')) {
                Permission::query()->where('guard_name', self::GUARD)->whereIn('name', $stale)->get()->each->delete();

                $this->components->warn('Deleted permissions that are no longer registered:');
            } else {
                $this->components->warn('Permissions no longer in the registry (run with --prune to delete them):');
            }

            $this->components->bulletList($stale);
        }

        $registrar->forgetCachedPermissions();

        return self::SUCCESS;
    }
}

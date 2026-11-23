<?php

namespace Hata\Database\Seeders;

use Hata\Enums\SystemRole;
use Hata\Models\Role;
use Hata\Support\Permissions\PermissionRegistry;
use Illuminate\Database\Seeder;

/**
 * Create the system roles and grant their default permissions.
 *
 * Safe to run repeatedly: permissions are only added, never revoked, so changes made
 * by administrators survive. Run `permissions:sync` first. "*" grants every permission but the
 * reserved ones (config/permissions.php).
 */
class RoleSeeder extends Seeder
{
    public function run(PermissionRegistry $registry): void
    {
        foreach (SystemRole::cases() as $systemRole) {
            $role = Role::firstOrCreate(
                ['name' => $systemRole->value, 'guard_name' => 'web'],
                ['is_system' => true],
            );

            if (! $role->is_system) {
                $role->update(['is_system' => true]);
            }

            $defaults = config("permissions.roles.{$systemRole->value}", []);
            $permissions = in_array('*', $defaults, true)
                ? array_values(array_diff($registry->all(), config('permissions.reserved', [])))
                : array_values(array_intersect($defaults, $registry->all()));

            $missing = array_values(array_diff($permissions, $role->permissions()->pluck('name')->all()));

            if ($missing !== []) {
                $role->givePermissionTo($missing);
            }
        }
    }
}

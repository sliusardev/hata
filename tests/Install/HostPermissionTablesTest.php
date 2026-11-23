<?php

use Hata\Models\Role;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

afterEach(function () {
    // the schema was built with the fixture migration: the next RefreshDatabase test rebuilds Hata's own
    RefreshDatabaseState::$migrated = false;
});

it('installs on a host that already ran spatie\'s migration and adds its role columns', function () {
    $this->app['migrator']->path(dirname(__DIR__).'/fixtures/host-spatie-migrations');

    $this->artisan('migrate:fresh')->assertSuccessful();

    expect(DB::table('migrations')->pluck('migration')->all())->toContain(
        '0001_01_01_000003_create_permission_tables',
        '2026_11_20_000003_create_permission_tables',
        '2026_11_20_000004_add_hata_columns_to_roles_table',
    )
        ->and(Schema::hasColumns('roles', ['label', 'is_system']))->toBeTrue();

    $role = Role::query()->create(['name' => 'writer', 'label' => 'Writer', 'guard_name' => 'web']);

    expect($role->fresh()->label)->toBe('Writer')
        ->and($role->fresh()->is_system)->toBeFalse();
});

it('leaves the host\'s roles, permissions, passkeys and user columns in place when Hata\'s migrations roll back', function () {
    $this->app['migrator']->path(dirname(__DIR__).'/fixtures/host-spatie-migrations');

    $this->artisan('migrate:fresh')->assertSuccessful();

    // Hata was installed after the host's own migrations: its migrations are the last batch
    DB::table('migrations')->where('migration', 'like', '2026_11_20_%')->update(['batch' => 2]);

    $userId = DB::table('users')->insertGetId(['name' => 'Host Person', 'email' => 'host@example.test', 'password' => 'x', 'locale' => 'uk']);
    $roleId = DB::table('roles')->insertGetId(['name' => 'host-role', 'guard_name' => 'web', 'label' => 'Host role']);
    $permissionId = DB::table('permissions')->insertGetId(['name' => 'host.permission', 'guard_name' => 'web']);
    DB::table('role_has_permissions')->insert(['role_id' => $roleId, 'permission_id' => $permissionId]);
    DB::table('passkeys')->insert(['user_id' => $userId, 'name' => 'Key', 'credential_id' => 'abc', 'credential' => '{}']);

    $this->artisan('migrate:rollback')->assertSuccessful();

    expect(DB::table('migrations')->where('batch', 2)->count())->toBe(0)
        ->and(Schema::hasTable('settings'))->toBeFalse()
        ->and(Schema::hasTable('entries'))->toBeFalse()
        ->and(DB::table('roles')->where('id', $roleId)->value('label'))->toBe('Host role')
        ->and(DB::table('permissions')->where('id', $permissionId)->exists())->toBeTrue()
        ->and(DB::table('role_has_permissions')->where('role_id', $roleId)->exists())->toBeTrue()
        ->and(DB::table('passkeys')->where('credential_id', 'abc')->exists())->toBeTrue()
        ->and(DB::table('users')->where('id', $userId)->value('locale'))->toBe('uk')
        ->and(Schema::hasColumn('users', 'search_index'))->toBeFalse();
});

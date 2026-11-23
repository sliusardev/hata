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

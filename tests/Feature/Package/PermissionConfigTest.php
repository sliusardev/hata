<?php

namespace Tests\Feature\Package;

use Hata\Models\Role;
use Illuminate\Foundation\Application;
use Illuminate\Support\ServiceProvider;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * A fresh host has no config/permission.php: the environment holds only the one value the host sets (the cache
 * lifetime) before the providers register, and spatie's defaults must come from Hata. A class of its own, because
 * the environment is built before any Pest closure runs.
 */
class SeedsHostPermissionConfig extends ServiceProvider
{
    public function register(): void
    {
        config(['permission' => ['cache' => ['expiration_time' => 1234]]]);
    }
}

class PermissionConfigTest extends TestCase
{
    /**
     * Testbench sets the environment after the providers have registered, so the host's config is seeded by a
     * provider that registers before every other one, as the host's own config files are loaded before them.
     *
     * @param  Application  $app
     * @return list<class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [SeedsHostPermissionConfig::class, ...parent::getPackageProviders($app)];
    }

    #[Test]
    public function it_boots_without_a_published_permission_config_and_gives_spatie_its_defaults(): void
    {
        $this->assertInstanceOf(PermissionRegistrar::class, app(PermissionRegistrar::class));
        $this->assertSame(Permission::class, config('permission.models.permission'));
        $this->assertSame(Role::class, config('permission.models.role'));
        $this->assertSame('roles', config('permission.table_names.roles'));
    }

    #[Test]
    public function it_keeps_what_the_host_sets_and_fills_the_rest_of_the_same_group(): void
    {
        $this->assertSame(1234, config('permission.cache.expiration_time'));
        $this->assertSame('spatie.permission.cache', config('permission.cache.key'));
    }
}

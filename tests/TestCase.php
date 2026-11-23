<?php

namespace Tests;

use App\Models\User;
use App\Providers\WorkbenchServiceProvider;
use Hata\HataServiceProvider;
use Hata\Support\Content\ContentTypes;
use Hata\Support\Content\Types\PagesType;
use Hata\Support\Permissions\PermissionRegistry;
use Illuminate\Foundation\Application;
use Laravel\Fortify\Features;
use Orchestra\Testbench\Concerns\WithWorkbench;
use Orchestra\Testbench\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    use WithWorkbench;

    /**
     * The host's other packages (Livewire, Flux, Fortify, spatie) are found by package discovery, as on a site.
     *
     * @var bool
     */
    protected $enablesPackageDiscoveries = true;

    protected function setUp(): void
    {
        parent::setUp();

        $this->useKitContentTypes();
    }

    /**
     * Hata itself is listed too: Testbench discovers the root package only from a manifest its CLI wrote, and
     * Larastan rewrites that manifest without it.
     *
     * @param  Application  $app
     * @return list<class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [HataServiceProvider::class, WorkbenchServiceProvider::class];
    }

    /**
     * The workbench is the host application: its User model, and its seeders for the maintenance page.
     *
     * @param  Application  $app
     */
    protected function defineEnvironment($app): void
    {
        $app->useDatabasePath(dirname(__DIR__).'/workbench/database');

        $app['config']->set('auth.providers.users.model', User::class);

        // Laravel 13's skeleton hardens these and Testbench's does not: sessions hold JSON only and the cache
        // unserializes no objects, so code that would break on a real site breaks here too.
        $app['config']->set([
            'session.serialization' => 'json',
            'cache.serializable_classes' => false,
        ]);
    }

    /**
     * The suite tests the content mechanism, not the site's own list of types: whatever `config/content.php` lists,
     * tests run with the pages type alone (and register a fixture type when they need another), so adding a type
     * to the site never changes a test's result and needs no test of its own.
     */
    private function useKitContentTypes(): void
    {
        $siteTypes = array_diff(array_keys($this->app->make(ContentTypes::class)->all()), ['pages']);

        if ($siteTypes === []) {
            return;
        }

        $ownsPermission = fn (string $permission): bool => in_array(explode('.', $permission)[0], $siteTypes, true);

        config([
            'content.types' => [PagesType::class],
            'permissions.roles' => array_map(
                fn (array $permissions): array => array_values(array_filter($permissions, fn (string $permission): bool => ! $ownsPermission($permission))),
                (array) config('permissions.roles'),
            ),
        ]);

        $this->app->forgetInstance(PermissionRegistry::class);
        $this->app->forgetInstance(ContentTypes::class);
        $this->app->make(ContentTypes::class);
    }

    protected function skipUnlessFortifyHas(string $feature, ?string $message = null): void
    {
        if (! Features::enabled($feature)) {
            $this->markTestSkipped($message ?? "Fortify feature [{$feature}] is not enabled.");
        }
    }
}

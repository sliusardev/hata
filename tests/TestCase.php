<?php

namespace Tests;

use App\Support\Content\ContentTypes;
use App\Support\Content\Types\PagesType;
use App\Support\Permissions\PermissionRegistry;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Laravel\Fortify\Features;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->useKitContentTypes();
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

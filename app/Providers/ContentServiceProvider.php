<?php

namespace App\Providers;

use App\Support\Content\ContentTypes;
use App\Support\Content\Types\PagesType;
use App\Support\Permissions\PermissionRegistry;
use Illuminate\Support\ServiceProvider;

class ContentServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(
            ContentTypes::class,
            fn (): ContentTypes => (new ContentTypes($this->app->make(PermissionRegistry::class)))->register(new PagesType),
        );
    }

    /**
     * Builds the registry, so the kit's types have registered their permissions before anything (such as
     * `permissions:sync`) reads them. A type registered later registers its own.
     */
    public function boot(): void
    {
        $this->app->make(ContentTypes::class);
    }
}

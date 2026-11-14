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
            fn (): ContentTypes => (new ContentTypes)->register(new PagesType),
        );
    }

    /**
     * Every type gets view, create, edit and delete permissions under its key.
     */
    public function boot(): void
    {
        $registry = $this->app->make(PermissionRegistry::class);

        foreach ($this->app->make(ContentTypes::class)->all() as $type) {
            $registry->register($type->key(), ['view', 'create', 'edit', 'delete']);
        }
    }
}

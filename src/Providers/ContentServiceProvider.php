<?php

namespace Hata\Providers;

use Hata\Support\Content\ContentType;
use Hata\Support\Content\ContentTypes;
use Hata\Support\Content\Types\PagesType;
use Hata\Support\Permissions\PermissionRegistry;
use Illuminate\Support\ServiceProvider;

class ContentServiceProvider extends ServiceProvider
{
    /**
     * Binds the registry with the types listed in `config/content.php` → `types`, in that order. A config cache
     * built before the list existed has no such key; the site then keeps its pages instead of losing every type.
     */
    public function register(): void
    {
        $this->app->singleton(ContentTypes::class, function (): ContentTypes {
            $types = new ContentTypes($this->app->make(PermissionRegistry::class));

            /** @var list<class-string<ContentType>> $classes */
            $classes = config('content.types') ?? [PagesType::class];

            foreach ($classes as $class) {
                $types->register($this->app->make($class));
            }

            return $types;
        });
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

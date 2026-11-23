<?php

namespace Hata\Providers;

use Hata\Contracts\AdminUser;
use Hata\Models\Entry;
use Hata\Support\Admin\AdminMenu;
use Hata\Support\Admin\AdminMenuItem;
use Hata\Support\Content\ContentType;
use Hata\Support\Content\ContentTypes;
use Hata\Support\Content\Types\PagesType;
use Hata\Support\Permissions\PermissionRegistry;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class ContentServiceProvider extends ServiceProvider
{
    /**
     * Binds the registry with the types listed in `config/hata.php` → `types`, in that order. A config cache
     * built before the list existed has no such key; the site then keeps its pages instead of losing every type.
     */
    public function register(): void
    {
        $this->app->singleton(ContentTypes::class, function (): ContentTypes {
            $types = new ContentTypes($this->app->make(PermissionRegistry::class));

            /** @var list<class-string<ContentType>> $classes */
            $classes = config('hata.content.types') ?? [PagesType::class];

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

        $this->app->make(AdminMenu::class)->lazy(fn (): array => $this->menuItems());
    }

    /**
     * The sidebar's content items: each active type, then the taxonomies that classify it (a taxonomy shows once,
     * nested under its first type). Worked out per render, because the registry and the on/off switch can change
     * after boot; a type shows to those who may list it.
     *
     * @return list<AdminMenuItem>
     */
    private function menuItems(): array
    {
        $registry = $this->app->make(ContentTypes::class);
        $placed = [];

        foreach ($registry->active() as $type) {
            if ($type->isTaxonomy()) {
                continue;
            }

            $placed[$type->key()] = [$type, null];

            foreach ($registry->taxonomiesOf($type->key()) as $taxonomy) {
                $placed[$taxonomy->key()] ??= [$taxonomy, $type->key()];
            }
        }

        $items = [];
        $order = 100;

        foreach ($placed as [$type, $parent]) {
            $items[] = new AdminMenuItem(
                key: 'content.'.$type->key(),
                label: $type->pluralLabel(),
                icon: $type->icon(),
                route: 'admin.content.index',
                permission: fn (AdminUser $user): bool => Gate::forUser($user)->allows('admin.access')
                    && Gate::forUser($user)->allows('viewAny', [Entry::class, $type->key()]),
                group: 'content',
                order: $order++,
                activePattern: 'admin.content.*',
                parent: $parent === null ? null : 'content.'.$parent,
                routeParameters: ['type' => $type->key()],
            );
        }

        return $items;
    }
}

<?php

namespace Hata\Support\Admin;

use Closure;
use Hata\Contracts\AdminUser;
use Hata\Support\Admin\Registry\Entry;
use Hata\Support\Admin\Registry\HasChanges;
use WeakMap;

/**
 * One item of the desktop top bar: a link (an icon button) or a Blade component a module renders itself.
 *
 * `$label` is a translation key or a plain string. A link points to a route (`$route`, with `$routeParameters`) or
 * to a plain `$url`. `$side` is `start` (left) or `end` (right); `$permission` works as for the menu items.
 */
final readonly class TopbarItem implements Entry
{
    use HasChanges;

    /**
     * @param  array<string, mixed>  $routeParameters
     * @param  string|Closure(AdminUser): bool|null  $permission
     */
    public function __construct(
        public string $key,
        public string $label = '',
        public string $icon = '',
        public string $route = '',
        public array $routeParameters = [],
        public ?string $url = null,
        public bool $newTab = false,
        public string|Closure|null $permission = null,
        public int $order = 100,
        public string $side = 'end',
        public ?string $component = null,
    ) {}

    /**
     * @param  array<string, mixed>  $routeParameters
     * @param  string|Closure(AdminUser): bool|null  $permission
     */
    public static function link(string $key, string $label, string $icon, string $route = '', array $routeParameters = [], ?string $url = null, bool $newTab = false, string|Closure|null $permission = null, int $order = 100, string $side = 'end'): self
    {
        return new self($key, $label, $icon, $route, $routeParameters, $url, $newTab, $permission, $order, $side);
    }

    /**
     * @param  string|Closure(AdminUser): bool|null  $permission
     */
    public static function component(string $key, string $component, string|Closure|null $permission = null, int $order = 100, string $side = 'end'): self
    {
        return new self($key, permission: $permission, order: $order, side: $side, component: $component);
    }

    /**
     * The link's address (a route's is relative, built once per item object), or null for a component.
     */
    public function href(): ?string
    {
        static $hrefs = new WeakMap;

        if ($this->component !== null) {
            return null;
        }

        return $hrefs[$this] ??= $this->route !== ''
            ? route($this->route, $this->routeParameters, absolute: false)
            : $this->url;
    }
}
